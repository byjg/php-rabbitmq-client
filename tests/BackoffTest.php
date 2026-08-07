<?php

use ByJG\MessageQueueClient\Connector\Pipe;
use ByJG\MessageQueueClient\Envelope;
use ByJG\MessageQueueClient\Message;
use ByJG\MessageQueueClient\RabbitMQ\RabbitMQConnector;
use ByJG\Util\Uri;
use PHPUnit\Framework\TestCase;

/**
 * Exposes the reconnection timing so it can be asserted without staging a broker outage.
 */
class ExposedRabbitMQConnector extends RabbitMQConnector
{
    public function backoffDelay(int $attempt): int
    {
        return $this->getBackoffDelay($attempt);
    }

    public function jitter(int $delaySeconds): int
    {
        return $this->applyJitter($delaySeconds);
    }
}

class BackoffTest extends TestCase
{
    private ExposedRabbitMQConnector $connector;

    #[\Override]
    public function setUp(): void
    {
        $this->connector = new ExposedRabbitMQConnector();
    }

    private function host(): string
    {
        return getenv('RABBITMQ_HOST') ?: '127.0.0.1';
    }

    /**
     * The delay must still double, jitter notwithstanding: each attempt's floor and ceiling both
     * follow 1, 2, 4, 8 ... so a broker that stays down is probed progressively less often.
     */
    public function testTheBackoffStillDoublesUnderJitter(): void
    {
        foreach (range(0, 12) as $attempt) {
            $computed = min(1 << $attempt, 30);

            for ($draw = 0; $draw < 30; $draw++) {
                $delay = $this->connector->backoffDelay($attempt);

                $this->assertGreaterThanOrEqual(
                    max(1, intdiv($computed, 2)),
                    $delay,
                    "Attempt $attempt fell below half its computed delay"
                );
                $this->assertLessThanOrEqual($computed, $delay, "Attempt $attempt exceeded its computed delay");
            }
        }
    }

    public function testTheBackoffIsCappedAtThirtySeconds(): void
    {
        foreach ([10, 20, 30, 62, 64, 128, PHP_INT_MAX] as $attempt) {
            $delay = $this->connector->backoffDelay($attempt);

            // A large attempt count must not shift past the integer width and wrap to something
            // nonsensical - negative, zero, or a delay measured in years.
            $this->assertGreaterThanOrEqual(1, $delay, "Attempt $attempt produced a non-positive delay");
            $this->assertLessThanOrEqual(30, $delay, "Attempt $attempt exceeded the 30 second cap");
        }
    }

    /**
     * The guaranteed half is what stops an unlucky run of draws turning the backoff into a tight
     * reconnect loop, so it is asserted directly rather than inferred.
     */
    public function testJitterNeverProducesAZeroDelay(): void
    {
        foreach ([1, 2, 4, 30] as $delaySeconds) {
            for ($draw = 0; $draw < 200; $draw++) {
                $this->assertGreaterThan(0, $this->connector->jitter($delaySeconds));
            }
        }
    }

    public function testJitterActuallyVariesTheDelay(): void
    {
        $draws = [];
        for ($i = 0; $i < 200; $i++) {
            $draws[] = $this->connector->jitter(30);
        }

        $this->assertGreaterThan(1, count(array_unique($draws)), 'The reconnect delay is not jittered at all');
    }

    public function testBackoffDelayIsAnIntegerNotACoercedFloat(): void
    {
        // pow() returns a float, so the previous implementation leaned on an implicit coercion that
        // declare(strict_types=1) would have turned into a TypeError.
        $this->assertIsInt($this->connector->backoffDelay(0));
        $this->assertIsInt($this->connector->backoffDelay(5));
    }

    /**
     * An idle queue is not a broken one.
     *
     * $channel->wait() throws AMQPTimeoutException simply because no message arrived within the
     * timeout, which on a low-traffic queue is the normal case. It used to count towards
     * max_attempts, so a quiet consumer eventually died with "Failed to recover connection" having
     * never lost a connection at all.
     *
     * The consumer is run in a child process because consume() does not return on an empty queue -
     * it is meant to keep waiting. Survival is therefore the assertion: with timeout=1 and
     * max_attempts=1 the old code exhausted its attempts and threw after roughly four seconds, so a
     * child still alive at seven has demonstrably stopped counting idle periods as failures.
     */
    #[\PHPUnit\Framework\Attributes\Group('slow')]
    public function testAnIdleQueueDoesNotExhaustTheReconnectAttempts(): void
    {
        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl is required to run the consumer out of process');
        }

        $host = $this->host();

        $pid = pcntl_fork();
        $this->assertNotSame(-1, $pid, 'Could not fork');

        if ($pid === 0) {
            $consumer = new RabbitMQConnector();
            $consumer->setUp(new Uri("amqp://guest:guest@$host:5672?pre_fetch=1&timeout=1&max_attempts=1"));

            try {
                $consumer->consume(
                    new Pipe('idle_timeout_test'),
                    fn(Envelope $envelope) => Message::ACK | Message::EXIT,
                    fn(Envelope $envelope, $ex) => throw $ex
                );
                exit(2); // Returned on its own, which consume() on an idle queue should never do.
            } catch (Throwable) {
                exit(1); // Gave up on a broker that never went away: the bug.
            }
        }

        sleep(7);

        $stillWaiting = pcntl_waitpid($pid, $status, WNOHANG) === 0;
        posix_kill($pid, SIGKILL);
        pcntl_waitpid($pid, $status);

        $this->assertTrue(
            $stillWaiting,
            'The consumer stopped waiting on an idle queue, so timeouts are still counting as failures'
        );
    }
}
