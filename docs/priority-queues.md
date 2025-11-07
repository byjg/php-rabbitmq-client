---
sidebar_position: 1
title: Priority Queues
---

# Priority Queues

RabbitMQ supports message prioritization, allowing higher priority messages to be delivered before lower priority ones. This is useful when you need to process certain messages with higher urgency than others.

## How Priority Queues Work

In RabbitMQ, priority queues allow messages to be processed based on their priority level rather than strictly in the order they arrive. Messages with higher priority values are delivered first, while messages with the same priority are processed in FIFO (first in, first out) order.

## Setting Up Priority Queues

To use priority queues in your application, you need to:

### 1. Declare the Queue with Maximum Priority

First, you must declare the queue with a maximum priority level using the `x-max-priority` property:

```php
<?php
use ByJG\MessageQueueClient\Connector\Pipe;

$pipe = new Pipe("priority_queue");
$pipe->withProperty('x-max-priority', 10); // Set max priority to 10
```

:::info
The `x-max-priority` property defines the maximum priority level for the queue. This MUST be set when creating the queue for priorities to work.
:::

### 2. Set Priority on Individual Messages

When publishing messages, set the priority using the `priority` property:

```php
<?php
use ByJG\MessageQueueClient\Message;

// High priority message
$highPriorityMessage = new Message("High Priority Message");
$highPriorityMessage->withProperty('priority', 10); // Highest priority

// Medium priority message
$mediumPriorityMessage = new Message("Medium Priority Message");
$mediumPriorityMessage->withProperty('priority', 5); // Medium priority

// Low priority message
$lowPriorityMessage = new Message("Low Priority Message");
$lowPriorityMessage->withProperty('priority', 1); // Low priority
```

### 3. Publish and Consume as Normal

Once configured, you can publish and consume messages normally. The RabbitMQ server will automatically deliver messages in priority order:

```php
<?php
use ByJG\MessageQueueClient\Envelope;

// Messages will be consumed in priority order (highest first)
$connector->publish(new Envelope($pipe, $lowPriorityMessage));
$connector->publish(new Envelope($pipe, $highPriorityMessage));
$connector->publish(new Envelope($pipe, $mediumPriorityMessage));

// When consuming, messages will be delivered in order: high, medium, low
$connector->consume(
    $pipe,
    function (Envelope $envelope) {
        echo "Processing: " . $envelope->getMessage()->getBody();
        return Message::ACK;
    },
    function (Envelope $envelope, $ex) {
        return Message::REQUEUE;
    }
);
```

## Important Considerations

:::warning Priority Range
Valid priority values range from 0 to 255, but RabbitMQ recommends using values between 0-10 for optimal performance. Higher numerical values indicate higher priority.
:::

### Key Points to Remember

- **Queue Declaration Required**: The queue MUST be declared with the `x-max-priority` argument for priorities to work
- **Priority Range**: While the AMQP specification allows 0-255, it's recommended to use 0-10 for performance reasons
- **Higher is Better**: Higher priority values have higher priority (10 > 5 > 1)
- **FIFO Within Priority**: Messages with the same priority are processed in FIFO order
- **Default Priority**: If no priority is set on a message, it defaults to priority 0 (lowest)
- **Performance Impact**: Using priority queues can have a slight performance impact compared to regular queues

## Complete Example

Here's a complete example demonstrating priority queue usage:

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

// Create a connector
$connector = ConnectorFactory::create(
    new Uri("amqp://guest:guest@localhost:5672/")
);

// Create a priority queue with max priority of 10
$pipe = new Pipe("priority_queue");
$pipe->withProperty('x-max-priority', 10);

// Create messages with different priorities
$messages = [
    ['body' => 'Low priority task', 'priority' => 1],
    ['body' => 'Critical task', 'priority' => 10],
    ['body' => 'Normal priority task', 'priority' => 5],
    ['body' => 'Another critical task', 'priority' => 10],
];

// Publish all messages
foreach ($messages as $msgData) {
    $message = new Message($msgData['body']);
    $message->withProperty('priority', $msgData['priority']);
    $connector->publish(new Envelope($pipe, $message));
    echo "Published: {$msgData['body']} (priority: {$msgData['priority']})\n";
}

// Consume messages - they will be processed in priority order
echo "\nConsuming messages:\n";
$connector->consume(
    $pipe,
    function (Envelope $envelope) {
        $priority = $envelope->getMessage()->getProperty('priority', 0);
        echo "Consumed: {$envelope->getMessage()->getBody()} (priority: {$priority})\n";
        return Message::ACK | Message::EXIT; // Process one message and exit
    },
    function (Envelope $envelope, $ex) {
        echo "Error: " . $ex->getMessage() . "\n";
        return Message::REQUEUE;
    }
);
```

Expected output:
```
Published: Low priority task (priority: 1)
Published: Critical task (priority: 10)
Published: Normal priority task (priority: 5)
Published: Another critical task (priority: 10)

Consuming messages:
Consumed: Critical task (priority: 10)
Consumed: Another critical task (priority: 10)
Consumed: Normal priority task (priority: 5)
Consumed: Low priority task (priority: 1)
```

## Use Cases

Priority queues are particularly useful for:

- **Critical Alerts**: Ensuring urgent notifications are processed immediately
- **Task Prioritization**: Processing important tasks before less critical ones
- **SLA Management**: Meeting service level agreements by prioritizing time-sensitive operations
- **System Health**: Processing health checks and monitoring messages with high priority
- **User Experience**: Prioritizing user-facing operations over background tasks

## See Also

- [Connection Parameters](connection-parameters.md)
- [Robust Connection Setup](robust-connections.md)
