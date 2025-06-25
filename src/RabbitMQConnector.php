<?php
namespace ByJG\MessageQueueClient\RabbitMQ;

use ByJG\MessageQueueClient\Connector\ConnectorInterface;
use ByJG\MessageQueueClient\Connector\Pipe;
use ByJG\MessageQueueClient\Envelope;
use ByJG\MessageQueueClient\Message;
use ByJG\Util\Uri;
use Closure;
use Error;
use Exception;
use InvalidArgumentException;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AbstractConnection;
use PhpAmqpLib\Connection\AMQPConnectionConfig;
use PhpAmqpLib\Connection\AMQPConnectionFactory;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Exchange\AMQPExchangeType;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;

class RabbitMQConnector implements ConnectorInterface
{
    public const ROUTING_KEY = '_x_routing_key';
    public const EXCHANGE = '_x_exchange';
    public const PARAM_CAPATH = 'capath';
    private const DEFAULT_TIMEOUT = 600;
    private const HEARTBEAT = 30;
    private const MAX_ATTEMPT = 10;

    #[\Override]
    public static function schema(): array
    {
        return ["amqp", "amqps"];
    }

    /** @var Uri */
    protected Uri $uri;

    #[\Override]
    public function setUp(Uri $uri): void
    {
        $this->uri = $uri;
    }

    /**
     * @return AbstractConnection
     * @throws InvalidArgumentException When capath parameter is missing for AMQPS connections
     */
    #[\Override]
    public function getDriver(): AbstractConnection
    {
        $vhost = trim($this->uri->getPath(), "/");
        if (empty($vhost)) {
            $vhost = "/";
        }

        $args = [];
        if (!empty($this->uri->getQuery())) {
            parse_str($this->uri->getQuery(), $args);
        }

        $config = new AMQPConnectionConfig();
        $config->setHost($this->uri->getHost());
        $config->setUser($this->uri->getUsername());
        $config->setPassword($this->uri->getPassword());
        $config->setVhost($vhost);
        $config->setHeartbeat(intval($this->uri->getQueryPart('heartbeat') ?? self::HEARTBEAT));
        $config->setReadTimeout(self::HEARTBEAT + 10);
        $config->setWriteTimeout(self::HEARTBEAT + 10);
        $config->setConnectionTimeout(intval($this->uri->getQueryPart('connection_timeout') ?? self::HEARTBEAT + 10));

        if ($this->uri->getScheme() == "amqps") {
            $port = 5671;
            if (empty($args[self::PARAM_CAPATH])) {
                throw new InvalidArgumentException("The 'capath' parameter is required for AMQPS");
            }

            $config->setPort(empty($this->uri->getPort()) ? $port : $this->uri->getPort());
            $config->setIsSecure(true);
            $config->setSslCaCert($this->uri->getQueryPart('local_cert'));
            $config->setSslCaPath($this->uri->getQueryPart(self::PARAM_CAPATH));
            $config->setSslKey($this->uri->getQueryPart('local_pk'));
            $config->setSslVerify($this->uri->getQueryPart('verify_peer') === 'true');
            $config->setSslVerifyName($this->uri->getQueryPart('verify_peer_name') === 'true');
            $config->setSslPassPhrase($this->uri->getQueryPart('passphrase'));
            $config->setSslCiphers($this->uri->getQueryPart('ciphers'));
        } else {
            $port = 5672;

            $config->setPort(empty($this->uri->getPort()) ? $port : $this->uri->getPort());
        }

        return AMQPConnectionFactory::create($config);
    }

    /**
     * Tests the connection to the RabbitMQ server
     * @return bool True if connection is working, false otherwise
     */
    public function testConnection(): bool
    {
        try {
            $driver = $this->getDriver();
            $isConnected = $driver->isConnected();
            $driver->close();
            return $isConnected;
        } catch (Exception $ex) {
            error_log("Failed to connect to RabbitMQ: " . $ex->getMessage());
            return false;
        }
    }

    /**
     * @param AbstractConnection $connection
     * @param Pipe $pipe
     * @param bool $withExchange
     * @return AMQPChannel
     */
    protected function createQueue(AbstractConnection $connection, Pipe $pipe, bool $withExchange = true): AMQPChannel
    {
        $pipe->setPropertyIfNull('exchange_type', AMQPExchangeType::DIRECT);
        $pipe->setPropertyIfNull(self::EXCHANGE, $pipe->getName());
        $pipe->setPropertyIfNull(self::ROUTING_KEY, $pipe->getName());

        // Get all queue properties
        $queueProperties = $pipe->getProperties();

        // Handle dead letter queue if present
        $dlq = $pipe->getDeadLetter();
        if (!empty($dlq)) {
            $dlq->withProperty('exchange_type', AMQPExchangeType::FANOUT);
            $channelDlq = $this->createQueue($connection, $dlq);
            $channelDlq->close();

            // Add dead letter properties
            $queueProperties['x-dead-letter-exchange'] = $dlq->getProperty(self::EXCHANGE, $dlq->getName());
            // $queueProperties['x-dead-letter-routing-key'] = $routingKey;
            // $queueProperties['x-message-ttl'] = $dlq->getProperty('x-message-ttl', 3600 * 72*1000);
            // $queueProperties['x-expires'] = $dlq->getProperty('x-expires', 3600 * 72*1000 + 1000);
        }

        // Create AMQP table with all queue properties
        $amqpTable = new AMQPTable($queueProperties);

        $channel = $connection->channel();

        /*
            name: $queue
            passive: false
            durable: true // the queue will survive server restarts
            exclusive: false // the queue can be accessed in other channels
            auto_delete: false //the queue won't be deleted once the channel is closed.
        */
        $channel->queue_declare($pipe->getName(), false, true, false, false, false, $amqpTable);

        /*
            name: $exchange
            type: direct
            passive: false
            durable: true // the exchange will survive server restarts
            auto_delete: false //the exchange won't be deleted once the channel is closed.
        */
        if ($withExchange) {
            $channel->exchange_declare($pipe->getProperty(self::EXCHANGE, $pipe->getName()), $pipe->getProperty('exchange_type'), false, true, false);
        }

        $channel->queue_bind($pipe->getName(), $pipe->getProperty(self::EXCHANGE, $pipe->getName()), $pipe->getProperty(self::ROUTING_KEY, $pipe->getName()));

        return $channel;
    }

    /**
     * @throws Exception
     * @return array<int, mixed>
     */
    protected function lazyConnect(Pipe $pipe, bool $withExchange = true): array
    {
        $driver = $this->getDriver();
        $channel = $this->createQueue($driver, $pipe, $withExchange);

        return [$driver, $channel];
    }


    /**
     * @throws Exception
     */
    #[\Override]
    public function publish(Envelope $envelope): void
    {
        $properties = $envelope->getMessage()->getProperties();
        $properties['content_type'] = $properties['content_type'] ?? 'text/plain';
        $properties['delivery_mode'] = $properties['delivery_mode'] ?? AMQPMessage::DELIVERY_MODE_PERSISTENT;

        $pipe = clone $envelope->getPipe();

        list($driver, $channel) = $this->lazyConnect($pipe);

        $rabbitMQMessageBody = $envelope->getMessage()->getBody();

        $rabbitMQMessage = new AMQPMessage($rabbitMQMessageBody, $properties);

        $channel->basic_publish($rabbitMQMessage, $pipe->getProperty(self::EXCHANGE, $pipe->getName()), $pipe->getName());

        $channel->close();
        $driver->close();
    }

    private function getBackoffDelay(int $attempt): int
    {
        return min(pow(2, $attempt), 30); // Caps at 30 seconds
    }

    /**
     * @throws Exception
     */
    #[\Override]
    public function consume(Pipe $pipe, Closure $onReceive, Closure $onError, ?string $identification = null): void
    {
        $pipe = clone $pipe;

        /**
         * @param AMQPMessage $rabbitMQMessage
         */
        $closure = function (AMQPMessage $rabbitMQMessage) use ($onReceive, $onError, $pipe) {
            $message = new Message($rabbitMQMessage->body);
            $message->withProperties($rabbitMQMessage->get_properties());
            $message->withProperty('consumer_tag', $rabbitMQMessage->getConsumerTag());
            $message->withProperty('delivery_tag', $rabbitMQMessage->getDeliveryTag());
            $message->withProperty('redelivered', $rabbitMQMessage->isRedelivered());
            $message->withProperty('exchange', $rabbitMQMessage->getExchange());
            $message->withProperty('routing_key', $rabbitMQMessage->getRoutingKey());
            $message->withProperty('body_size', $rabbitMQMessage->getBodySize());
            $message->withProperty('message_count', $rabbitMQMessage->getMessageCount());

            $envelope = new Envelope($pipe, $message);

            try {
                $result = $onReceive($envelope);
                if (!is_null($result) && (($result & Message::NACK) == Message::NACK)) {
                    // echo "NACK\n";
                    // echo ($result & Message::REQUEUE) == Message::REQUEUE ? "REQUEUE\n" : "NO REQUEUE\n";
                    $rabbitMQMessage->nack(($result & Message::REQUEUE) == Message::REQUEUE);
                } else {
                    // echo "ACK\n";
                    $rabbitMQMessage->ack();
                }

                if (($result & Message::EXIT) == Message::EXIT) {
                    $rabbitMQMessage->getChannel()->basic_cancel($rabbitMQMessage->getConsumerTag());
                    $currentConnection = $rabbitMQMessage->getChannel()->getConnection();
                    $rabbitMQMessage->getChannel()->close();
                    $currentConnection->close();
                }
            } catch (Exception | Error $ex) {
                $result = $onError($envelope, $ex);
                if (!is_null($result) && (($result & Message::NACK) == Message::NACK)) {
                    $rabbitMQMessage->nack(($result & Message::REQUEUE) == Message::REQUEUE);
                } else {
                    $rabbitMQMessage->ack();
                }

                if (($result & Message::EXIT) == Message::EXIT) {
                    $rabbitMQMessage->getChannel()->basic_cancel($rabbitMQMessage->getConsumerTag());
                }
            }
        };

        $preFetch = intval($this->uri->getQueryPart("pre_fetch"));
        $timeout = intval($this->uri->getQueryPart("timeout") ?? self::DEFAULT_TIMEOUT);
        $singleRun = $this->uri->getQueryPart("single_run") === "true";
        $attempt = 0;
        $maxAttempts = intval($this->uri->getQueryPart("max_attempts") ?? self::MAX_ATTEMPT);

        while (true) {
            $driver = null;
            $channel = null;

            try {
                /**
                 * @var AbstractConnection $driver
                 * @var AMQPChannel $channel
                 */
                list($driver, $channel) = $this->lazyConnect($pipe, false);

                /*
                    pipe: Queue from where to get the messages
                    consumer_tag: Consumer identifier
                    no_local: Don't receive messages published by this consumer.
                    no_ack: If set to true, automatic acknowledgement mode will be used by this consumer. See https://www.rabbitmq.com/confirms.html for details.
                    exclusive: Request exclusive consumer access, meaning only this consumer can access the queue
                    nowait:
                    callback: A PHP Callback
                */
                if ($preFetch !== 0) {
                    $channel->basic_qos(0, $preFetch, null);
                }
                $channel->basic_consume($pipe->getName(), $identification ?? $pipe->getName(), false, false, false, false, $closure);

                // Attempt counter continues across connections

                while ($channel->is_consuming()) {
                    $channel->wait(null, false, $timeout);
                }

                // Reset attempt counter after successful consumption cycle
                $attempt = 0;
            } catch (AMQPTimeoutException $ex) {
                $delay = $this->getBackoffDelay($attempt++);
                if ($attempt > $maxAttempts) {
                    throw new Exception("Failed to recover connection after {$maxAttempts} attempts", 0, $ex);
                }
//                error_log("RabbitMQ connection timeout. Reconnecting in {$delay} seconds. Attempt {$attempt}/{$maxAttempts}");
                sleep($delay);
            } catch (Exception | Error $ex) {
                $delay = $this->getBackoffDelay($attempt++);
                if ($attempt > $maxAttempts) {
                    throw new Exception("Failed to recover connection after {$maxAttempts} attempts", 0, $ex);
                }
//                error_log("RabbitMQ connection error: " . $ex->getMessage() . ". Reconnecting in {$delay} seconds. Attempt {$attempt}/{$maxAttempts}");
                sleep($delay);
            } finally {
                if ($channel !== null) {
                    try {
                        $channel->close();
                    } catch (Exception $ex) {
                        // Ignore errors when closing an already broken channel
                    }
                }
                if ($driver !== null) {
                    try {
                        $driver->close();
                    } catch (Exception $ex) {
                        // Ignore errors when closing an already broken connection
                    }
                }
            }

            if ($singleRun) {
                break;
            }

            // A small delay before attempting to reconnect
            sleep(1);
        }
    }

}
