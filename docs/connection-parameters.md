---
sidebar_position: 2
title: Connection Parameters
---

# Connection Parameters

The RabbitMQ connector supports various connection parameters that can be configured via the connection URI query string. These parameters control connection behavior, timeouts, reconnection logic, and message consumption patterns.

## Overview

Connection parameters are passed as query string arguments in the connection URI:

```php
$uri = "amqp://user:pass@host:port/vhost?param1=value1&param2=value2";
```

All parameters are optional and have sensible defaults for most use cases.

## Available Parameters

### heartbeat

**Type:** Integer
**Default:** 30 seconds
**Description:** Interval in seconds to send heartbeat frames to keep the connection alive during periods of inactivity.

```php
$uri = "amqp://guest:guest@localhost:5672/?heartbeat=30";
```

Heartbeats are critical for detecting dead connections, especially when:
- The network has a long idle timeout
- The connection goes through firewalls or load balancers
- You need to detect connection failures quickly

:::tip Recommendation
Keep the default of 30 seconds unless you have specific requirements. Lower values increase network traffic but provide faster failure detection.
:::

### connection_timeout

**Type:** Integer
**Default:** 40 seconds (heartbeat + 10)
**Description:** Timeout in seconds when establishing a new connection to the RabbitMQ server.

```php
$uri = "amqp://guest:guest@localhost:5672/?connection_timeout=10";
```

This parameter controls how long the client will wait when initially connecting to RabbitMQ. Set it lower if you want to fail fast, or higher if connecting over slow networks.

### max_attempts

**Type:** Integer
**Default:** 10 attempts
**Description:** Maximum number of reconnection attempts before giving up when the connection is lost.

```php
$uri = "amqp://guest:guest@localhost:5672/?max_attempts=5";
```

The connector implements exponential backoff when reconnecting:
- Attempt 1: 2 seconds delay
- Attempt 2: 4 seconds delay
- Attempt 3: 8 seconds delay
- Attempt 4: 16 seconds delay
- Attempt 5: 30 seconds delay (capped)
- And so on...

:::warning
Setting this too low may cause the consumer to give up during temporary network issues. Setting it too high may cause excessive wait times during permanent failures.
:::

### pre_fetch

**Type:** Integer
**Default:** 0 (no limit)
**Description:** Controls how many messages the server will deliver before requiring acknowledgements (QoS prefetch count).

```php
$uri = "amqp://guest:guest@localhost:5672/?pre_fetch=10";
```

This parameter is crucial for load balancing:
- **0**: No limit - server will deliver as many messages as possible (default)
- **1**: Fair dispatch - one message at a time, ideal for even load distribution
- **N**: Batch processing - deliver N messages before requiring acknowledgement

**Common use cases:**

```php
// Fair dispatch: ideal when messages have variable processing time
$uri = "amqp://guest:guest@localhost:5672/?pre_fetch=1";

// Batch processing: good for consistent, fast processing
$uri = "amqp://guest:guest@localhost:5672/?pre_fetch=50";
```

:::info Load Balancing
When using multiple consumers, set `pre_fetch=1` to ensure even distribution of work. Without this, RabbitMQ may send all messages to one consumer.
:::

### timeout

**Type:** Integer
**Default:** 600 seconds (10 minutes)
**Description:** Timeout in seconds for waiting for messages when consuming.

```php
$uri = "amqp://guest:guest@localhost:5672/?timeout=60";
```

This parameter controls how long the `wait()` method will block when waiting for new messages. After the timeout:
- The connection is checked
- If no messages arrived, the consumer continues waiting
- This provides an opportunity to detect dead connections

:::tip Long-running Consumers
For long-running consumers, keep this at a reasonable value (60-600 seconds) to allow periodic connection health checks.
:::

### single_run

**Type:** String (true/false)
**Default:** "false"
**Description:** When set to "true", the consumer will exit after processing one batch of messages instead of continuously waiting.

```php
$uri = "amqp://guest:guest@localhost:5672/?single_run=true";
```

This is useful for:
- **Testing**: Process a few messages and exit
- **Scheduled Jobs**: Run the consumer via cron or scheduled tasks
- **Batch Processing**: Process available messages and exit

```php
// Continuously consume messages (default behavior)
$uri = "amqp://guest:guest@localhost:5672/?single_run=false";

// Process available messages and exit
$uri = "amqp://guest:guest@localhost:5672/?single_run=true&pre_fetch=100";
```

## Configuration Examples

### Development Environment

For local development with fast failure detection:

```php
<?php
use ByJG\MessageQueueClient\Connector\ConnectorFactory;
use ByJG\Util\Uri;

$uri = "amqp://guest:guest@localhost:5672/?heartbeat=10&connection_timeout=5&timeout=30";
$connector = ConnectorFactory::create(new Uri($uri));
```

### Production Environment

For production with robust connection handling:

```php
<?php
$uri = "amqp://user:pass@rabbitmq.example.com:5672/production" .
       "?heartbeat=30" .
       "&connection_timeout=10" .
       "&max_attempts=10" .
       "&timeout=60" .
       "&pre_fetch=1";

$connector = ConnectorFactory::create(new Uri($uri));
```

### High-Throughput Consumer

For processing large volumes of messages efficiently:

```php
<?php
$uri = "amqp://user:pass@rabbitmq.example.com:5672/production" .
       "?heartbeat=30" .
       "&pre_fetch=100" .        // Batch processing
       "&timeout=300";           // Longer timeout for steady flow

$connector = ConnectorFactory::create(new Uri($uri));
```

### Fair Load Balancing

For even distribution across multiple consumers:

```php
<?php
$uri = "amqp://user:pass@rabbitmq.example.com:5672/production" .
       "?heartbeat=30" .
       "&pre_fetch=1";           // One message at a time for fair dispatch

$connector = ConnectorFactory::create(new Uri($uri));
```

### Scheduled/Cron Consumer

For periodic processing via cron:

```php
<?php
$uri = "amqp://user:pass@rabbitmq.example.com:5672/production" .
       "?single_run=true" .      // Exit after one batch
       "&pre_fetch=100" .        // Process up to 100 messages
       "&timeout=5";             // Short timeout if queue is empty

$connector = ConnectorFactory::create(new Uri($uri));
```

## Connection Testing

You can test the connection before starting to consume:

```php
<?php
use ByJG\MessageQueueClient\RabbitMQ\RabbitMQConnector;
use ByJG\Util\Uri;

$connectionUri = "amqp://user:pass@host:5672/?heartbeat=30&connection_timeout=10";

$connector = new RabbitMQConnector();
$connector->setUp(new Uri($connectionUri));

if (!$connector->testConnection()) {
    die("Failed to connect to RabbitMQ server.\n");
}

echo "Connection successful!\n";
```

## Performance Considerations

### CPU vs. I/O Bound Tasks

**CPU-bound tasks** (heavy computation):
```php
?pre_fetch=1  // Process one at a time
```

**I/O-bound tasks** (API calls, database queries):
```php
?pre_fetch=10-50  // Process multiple in parallel
```

### Memory Constraints

If your messages are large or consume significant memory during processing:

```php
?pre_fetch=1  // Prevent memory exhaustion
```

### Network Stability

**Stable network**:
```php
?heartbeat=60&timeout=600&max_attempts=5
```

**Unstable network**:
```php
?heartbeat=15&timeout=120&max_attempts=20
```

## Troubleshooting

### Connection Keeps Timing Out

```php
// Increase connection timeout
?connection_timeout=30
```

### Consumer Exits Unexpectedly

```php
// Increase max_attempts and add better reconnection handling
?max_attempts=20
```

### Uneven Load Distribution

```php
// Enable fair dispatch
?pre_fetch=1
```

### High Memory Usage

```php
// Reduce pre_fetch to limit in-flight messages
?pre_fetch=1
```

## See Also

- [SSL/TLS Configuration](ssl-configuration.md) - Secure connection parameters
- [Robust Connection Setup](robust-connections.md) - Best practices for production
- [Priority Queues](priority-queues.md) - Message prioritization
