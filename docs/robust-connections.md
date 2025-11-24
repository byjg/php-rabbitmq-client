---
sidebar_position: 4
title: Robust Connection Setup
---

# Robust Connection Setup

For production applications that need to maintain reliable, long-lived connections to RabbitMQ, proper connection configuration is crucial. This guide covers best practices for building resilient consumers and publishers that can handle network issues, server restarts, and other failure scenarios.

## Why Robust Connections Matter

Production applications face various challenges:
- **Network instability**: Temporary network issues, packet loss, or latency spikes
- **Server maintenance**: RabbitMQ server restarts or upgrades
- **Firewall timeouts**: Long idle periods causing connection drops
- **Resource constraints**: Memory or connection limits
- **Load balancing**: Connections through load balancers or proxies

A robust connection configuration ensures your application can:
- Detect failed connections quickly
- Reconnect automatically with exponential backoff
- Continue processing messages after recovery
- Maintain message delivery guarantees

## Recommended Configuration

### Production-Ready URI

```php
<?php
use ByJG\MessageQueueClient\Connector\ConnectorFactory;
use ByJG\MessageQueueClient\RabbitMQ\RabbitMQConnector;
use ByJG\Util\Uri;

ConnectorFactory::registerConnector(RabbitMQConnector::class);

$connectionUri = "amqp://user:password@rabbitmq.example.com:5672/production" .
                 "?heartbeat=30" .          // Keep-alive every 30 seconds
                 "&connection_timeout=10" . // 10 second connection timeout
                 "&max_attempts=10" .       // Retry up to 10 times
                 "&timeout=60" .            // 60 second message wait timeout
                 "&pre_fetch=1";            // Fair dispatch for even load distribution

$connector = ConnectorFactory::create(new Uri($connectionUri));
```

### Configuration Breakdown

Let's understand each parameter:

#### heartbeat=30

**Purpose:** Keeps the connection alive and detects dead connections

```php
?heartbeat=30
```

- Sends a heartbeat frame every 30 seconds during idle periods
- Both client and server monitor for missed heartbeats
- Connection is closed if heartbeat is missed (2x interval = 60 seconds)
- Essential for detecting network failures quickly

**Tuning:**
- **Stable networks:** 60 seconds (less overhead)
- **Standard:** 30 seconds (recommended)
- **Unstable networks:** 15 seconds (faster detection)

#### connection_timeout=10

**Purpose:** How long to wait when establishing initial connection

```php
?connection_timeout=10
```

- Fails fast if RabbitMQ is unavailable
- Prevents hanging during startup
- Should be higher than network latency

**Tuning:**
- **Local development:** 5 seconds
- **Production:** 10-15 seconds
- **Slow networks:** 30 seconds

#### max_attempts=10

**Purpose:** Maximum reconnection attempts with exponential backoff

```php
?max_attempts=10
```

- Attempts to reconnect after connection loss
- Uses exponential backoff: 2s, 4s, 8s, 16s, 30s (capped)
- Gives up after 10 attempts
- Total wait time: ~5-6 minutes before giving up

**Backoff schedule:**
```
Attempt 1: 2 seconds
Attempt 2: 4 seconds
Attempt 3: 8 seconds
Attempt 4: 16 seconds
Attempt 5: 30 seconds (capped)
Attempt 6-10: 30 seconds each
```

**Tuning:**
- **Critical services:** 20+ attempts (longer recovery time)
- **Standard:** 10 attempts (recommended)
- **Non-critical:** 5 attempts (fail faster)

#### timeout=60

**Purpose:** How long to wait for new messages during consumption

```php
?timeout=60
```

- Consumer waits up to 60 seconds for new messages
- Allows periodic connection health checks
- Doesn't affect message processing time

**Tuning:**
- **High-volume queues:** 300-600 seconds
- **Standard:** 60 seconds (recommended)
- **Low-volume queues:** 30 seconds

#### pre_fetch=1

**Purpose:** Fair message distribution across consumers

```php
?pre_fetch=1
```

- Server sends one message at a time
- Next message only sent after previous is acknowledged
- Ensures even load distribution with multiple consumers
- Prevents one consumer from being overloaded

**Tuning:**
- **Fair dispatch:** 1 (recommended for multiple consumers)
- **High throughput:** 10-50 (single consumer processing quickly)
- **Memory-intensive:** 1 (prevent memory exhaustion)

## Complete Implementation Examples

### Reliable Consumer

```php
<?php
use ByJG\MessageQueueClient\Connector\ConnectorFactory;
use ByJG\MessageQueueClient\Connector\Pipe;
use ByJG\MessageQueueClient\Envelope;
use ByJG\MessageQueueClient\Message;
use ByJG\MessageQueueClient\RabbitMQ\RabbitMQConnector;
use ByJG\Util\Uri;

// Register the connector
ConnectorFactory::registerConnector(RabbitMQConnector::class);

// Robust connection configuration
$connectionUri = "amqp://user:password@rabbitmq.example.com:5672/production" .
                 "?heartbeat=30" .
                 "&connection_timeout=10" .
                 "&max_attempts=10" .
                 "&timeout=60" .
                 "&pre_fetch=1";

// Test connection before starting
$testConnector = new RabbitMQConnector();
$testConnector->setUp(new Uri($connectionUri));

if (!$testConnector->testConnection()) {
    error_log("Failed to connect to RabbitMQ server");
    exit(1);
}

echo "✓ Connected to RabbitMQ successfully\n";

// Create connector for consuming
$connector = ConnectorFactory::create(new Uri($connectionUri));

// Setup queue with dead letter queue
$pipe = new Pipe("orders");
$pipe->withDeadLetter(new Pipe("orders_dlq"));

echo "Starting consumer...\n";

// Consume messages with error handling
$connector->consume(
    $pipe,
    function (Envelope $envelope) {
        try {
            // Process the message
            $order = json_decode($envelope->getMessage()->getBody(), true);

            echo "Processing order: {$order['id']}\n";

            // Your business logic here
            processOrder($order);

            // Acknowledge successful processing
            return Message::ACK;

        } catch (Exception $ex) {
            error_log("Failed to process message: " . $ex->getMessage());
            throw $ex; // Let error handler decide
        }
    },
    function (Envelope $envelope, $ex) {
        error_log("Error processing message: " . $ex->getMessage());

        // Determine retry strategy based on error type
        if ($ex instanceof ValidationException) {
            // Invalid message, send to DLQ (don't requeue)
            error_log("Invalid message, sending to DLQ");
            return Message::NACK;
        }

        if ($ex instanceof TemporaryException) {
            // Temporary issue, requeue for retry
            error_log("Temporary error, requeuing message");
            return Message::REQUEUE;
        }

        // Check redelivery count
        $redelivered = $envelope->getMessage()->getProperty('redelivered', false);
        if ($redelivered) {
            // Already tried once, send to DLQ
            error_log("Message already redelivered, sending to DLQ");
            return Message::NACK;
        }

        // First failure, requeue
        error_log("First failure, requeuing message");
        return Message::REQUEUE;
    }
);

function processOrder(array $order): void
{
    // Your business logic
    // This function should throw exceptions on failure
}
```

### Robust Publisher

```php
<?php
use ByJG\MessageQueueClient\Connector\ConnectorFactory;
use ByJG\MessageQueueClient\Connector\Pipe;
use ByJG\MessageQueueClient\Envelope;
use ByJG\MessageQueueClient\Message;
use ByJG\MessageQueueClient\RabbitMQ\RabbitMQConnector;
use ByJG\Util\Uri;

class RobustPublisher
{
    private $connector;
    private $pipe;

    public function __construct(string $queueName)
    {
        ConnectorFactory::registerConnector(RabbitMQConnector::class);

        $connectionUri = "amqp://user:password@rabbitmq.example.com:5672/production" .
                         "?heartbeat=30" .
                         "&connection_timeout=10" .
                         "&max_attempts=5";  // Fewer attempts for publishing

        // Test connection
        $testConnector = new RabbitMQConnector();
        $testConnector->setUp(new Uri($connectionUri));

        if (!$testConnector->testConnection()) {
            throw new RuntimeException("Failed to connect to RabbitMQ");
        }

        $this->connector = ConnectorFactory::create(new Uri($connectionUri));
        $this->pipe = new Pipe($queueName);
        $this->pipe->withDeadLetter(new Pipe("{$queueName}_dlq"));
    }

    public function publish(array $data, int $priority = 0): bool
    {
        $maxRetries = 3;
        $attempt = 0;

        while ($attempt < $maxRetries) {
            try {
                $message = new Message(json_encode($data));
                $message->withProperty('content_type', 'application/json');
                $message->withProperty('delivery_mode', 2); // Persistent

                if ($priority > 0) {
                    $message->withProperty('priority', $priority);
                }

                $this->connector->publish(new Envelope($this->pipe, $message));

                return true;

            } catch (Exception $ex) {
                $attempt++;
                error_log("Publish attempt {$attempt} failed: " . $ex->getMessage());

                if ($attempt < $maxRetries) {
                    // Exponential backoff
                    $delay = min(pow(2, $attempt), 10);
                    sleep($delay);
                } else {
                    error_log("Failed to publish after {$maxRetries} attempts");
                    throw $ex;
                }
            }
        }

        return false;
    }
}

// Usage
try {
    $publisher = new RobustPublisher("orders");

    $order = [
        'id' => 12345,
        'customer' => 'john@example.com',
        'total' => 99.99
    ];

    if ($publisher->publish($order, 5)) {
        echo "Order published successfully\n";
    }

} catch (Exception $ex) {
    error_log("Failed to publish order: " . $ex->getMessage());
    // Handle failure (e.g., save to database for later retry)
}
```

## Environment-Specific Configurations

### Development

```php
<?php
// Fast feedback, less resilience
$uri = "amqp://guest:guest@localhost:5672/" .
       "?heartbeat=10" .
       "&connection_timeout=5" .
       "&max_attempts=3" .
       "&timeout=30";
```

### Staging

```php
<?php
// Balance between development and production
$uri = "amqp://user:pass@staging-rabbitmq:5672/staging" .
       "?heartbeat=20" .
       "&connection_timeout=8" .
       "&max_attempts=5" .
       "&timeout=60";
```

### Production

```php
<?php
// Maximum resilience and reliability
$uri = "amqp://user:pass@rabbitmq.example.com:5672/production" .
       "?heartbeat=30" .
       "&connection_timeout=10" .
       "&max_attempts=10" .
       "&timeout=60" .
       "&pre_fetch=1";
```

### High-Availability Production

```php
<?php
// For critical services requiring maximum uptime
$uri = "amqp://user:pass@rabbitmq.example.com:5672/production" .
       "?heartbeat=15" .              // Faster failure detection
       "&connection_timeout=10" .
       "&max_attempts=20" .           // More retry attempts
       "&timeout=30" .                // More frequent health checks
       "&pre_fetch=1";
```

## Monitoring and Health Checks

### Connection Health Check

```php
<?php
function checkRabbitMQHealth(Uri $uri): array
{
    $start = microtime(true);

    try {
        $connector = new RabbitMQConnector();
        $connector->setUp($uri);

        $isHealthy = $connector->testConnection();
        $latency = (microtime(true) - $start) * 1000; // Convert to ms

        return [
            'healthy' => $isHealthy,
            'latency_ms' => round($latency, 2),
            'timestamp' => time(),
        ];

    } catch (Exception $ex) {
        return [
            'healthy' => false,
            'error' => $ex->getMessage(),
            'timestamp' => time(),
        ];
    }
}

// Usage
$uri = new Uri("amqp://user:pass@rabbitmq.example.com:5672/?heartbeat=30");
$health = checkRabbitMQHealth($uri);

if ($health['healthy']) {
    echo "✓ RabbitMQ is healthy (latency: {$health['latency_ms']}ms)\n";
} else {
    echo "✗ RabbitMQ is unhealthy: {$health['error']}\n";
}
```

### Logging and Metrics

```php
<?php
// Log connection events
$connector->consume(
    $pipe,
    function (Envelope $envelope) {
        $start = microtime(true);

        try {
            // Process message
            processMessage($envelope);

            $duration = (microtime(true) - $start) * 1000;

            // Log metrics
            logMetric('message.processed', 1);
            logMetric('message.duration_ms', $duration);

            return Message::ACK;

        } catch (Exception $ex) {
            logMetric('message.failed', 1);
            error_log("Message processing failed: " . $ex->getMessage());
            throw $ex;
        }
    },
    function (Envelope $envelope, $ex) {
        logMetric('message.error', 1);
        return Message::NACK;
    }
);

function logMetric(string $name, $value): void
{
    // Send to your metrics system (Prometheus, CloudWatch, etc.)
    echo "[METRIC] {$name}: {$value}\n";
}
```

## Graceful Shutdown

Implement graceful shutdown to ensure clean connection closure:

```php
<?php
class GracefulConsumer
{
    private $shouldStop = false;

    public function __construct()
    {
        // Register signal handlers
        pcntl_signal(SIGTERM, [$this, 'handleShutdown']);
        pcntl_signal(SIGINT, [$this, 'handleShutdown']);
    }

    public function handleShutdown(): void
    {
        echo "Shutdown signal received, finishing current message...\n";
        $this->shouldStop = true;
    }

    public function start(ConnectorInterface $connector, Pipe $pipe): void
    {
        $connector->consume(
            $pipe,
            function (Envelope $envelope) {
                // Process message
                processMessage($envelope);

                // Check if we should stop
                pcntl_signal_dispatch();

                if ($this->shouldStop) {
                    echo "Gracefully shutting down...\n";
                    return Message::ACK | Message::EXIT;
                }

                return Message::ACK;
            },
            function (Envelope $envelope, $ex) {
                error_log("Error: " . $ex->getMessage());
                return Message::NACK;
            }
        );
    }
}

// Usage
$consumer = new GracefulConsumer();
$consumer->start($connector, $pipe);
```

## Best Practices Summary

### ✅ Do

1. **Always test connections before consuming/publishing**
   ```php
   if (!$connector->testConnection()) {
       throw new RuntimeException("Cannot connect to RabbitMQ");
   }
   ```

2. **Use appropriate timeouts for your environment**
   ```php
   ?heartbeat=30&connection_timeout=10&timeout=60
   ```

3. **Implement proper error handling**
   ```php
   try {
       $connector->publish($envelope);
   } catch (Exception $ex) {
       // Log and handle error
   }
   ```

4. **Use dead letter queues for failed messages**
   ```php
   $pipe->withDeadLetter(new Pipe("dlq_queue"));
   ```

5. **Monitor connection health and metrics**
   ```php
   logMetric('rabbitmq.connection.healthy', 1);
   ```

6. **Implement graceful shutdown**
   ```php
   return Message::ACK | Message::EXIT;
   ```

### ❌ Don't

1. **Don't use infinite timeouts**
   ```php
   // BAD: No timeout can cause indefinite blocking
   ```

2. **Don't ignore connection failures**
   ```php
   // BAD: Silently catching and ignoring exceptions
   try {
       $connector->publish($envelope);
   } catch (Exception $ex) {
       // Ignoring error
   }
   ```

3. **Don't use default settings in production**
   ```php
   // BAD: No connection parameters
   $uri = "amqp://user:pass@host:5672/";
   ```

4. **Don't set pre_fetch too high with slow processing**
   ```php
   // BAD: Can cause memory issues
   ?pre_fetch=1000  // With slow message processing
   ```

## Troubleshooting

### Consumer Stops Unexpectedly

**Symptoms:** Consumer exits without processing all messages

**Solutions:**
1. Increase `max_attempts`
2. Check error logs for connection issues
3. Verify network stability
4. Ensure RabbitMQ server is accessible

### Messages Not Being Distributed Evenly

**Symptoms:** One consumer gets all messages while others are idle

**Solution:** Set `pre_fetch=1` for fair dispatch

```php
?pre_fetch=1
```

### Connection Timeouts During Idle Periods

**Symptoms:** Connection drops after periods of no activity

**Solution:** Reduce heartbeat interval

```php
?heartbeat=15  // More frequent heartbeats
```

### High Memory Usage

**Symptoms:** Consumer memory grows continuously

**Solutions:**
1. Reduce `pre_fetch` value
2. Process messages one at a time
3. Check for memory leaks in processing code

```php
?pre_fetch=1  // Process one message at a time
```

## See Also

- [Connection Parameters](connection-parameters.md) - Detailed parameter reference
- [SSL/TLS Configuration](ssl-configuration.md) - Secure connections
- [Priority Queues](priority-queues.md) - Message prioritization
