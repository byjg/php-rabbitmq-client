# RabbitMQ Client

[![Build Status](https://github.com/byjg/php-rabbitmq-client/actions/workflows/phpunit.yml/badge.svg?branch=main)](https://github.com/byjg/php-rabbitmq-client/actions/workflows/phpunit.yml)
[![Opensource ByJG](https://img.shields.io/badge/opensource-byjg-success.svg)](http://opensource.byjg.com)
[![GitHub source](https://img.shields.io/badge/Github-source-informational?logo=github)](https://github.com/byjg/php-rabbitmq-client/)
[![GitHub license](https://img.shields.io/github/license/byjg/php-rabbitmq-client.svg)](https://opensource.byjg.com/opensource/licensing.html)
[![GitHub release](https://img.shields.io/github/release/byjg/php-rabbitmq-client.svg)](https://github.com/byjg/php-rabbitmq-client/releases/)

It creates a simple abstraction layer to publish and consume messages from the RabbitMQ Server using the component [byjg/message-queue-client](https://github.com/byjg/message-queue-client).

For details on how to use the Message Queue Client see the [documentation](https://github.com/byjg/message-queue-client)

## Usage

### Publish

```php
<?php
// Register the connector and associate with a scheme
ConnectorFactory::registerConnector(RabbitMQConnector::class);

// Create a connector
$connector = ConnectorFactory::create(new Uri("amqp://$user:$pass@$host:$port/$vhost"));

// Create a queue
$pipe = new Pipe("test");
$pipe->withDeadLetter(new Pipe("dlq_test"));

// Create a message
$message = new Message("Hello World");

// Publish the message into the queue
$connector->publish(new Envelope($pipe, $message));
```

### Consume

```php
<?php
// Register the connector and associate with a scheme
ConnectorFactory::registerConnector(RabbitMQConnector::class);

// Create a connector
$connector = ConnectorFactory::create(new Uri("amqp://$user:$pass@$host:$port/$vhost"));

// Create a queue
$pipe = new Pipe("test");
$pipe->withDeadLetter(new Pipe("dlq_test"));

// Connect to the queue and wait to consume the message
$connector->consume(
    $pipe,                                 // Queue name
    function (Envelope $envelope) {         // Callback function to process the message
        echo "Process the message";
        echo $envelope->getMessage()->getBody();
        return Message::ACK;
    },
    function (Envelope $envelope, $ex) {    // Callback function to process the failed message
        echo "Process the failed message";
        echo $ex->getMessage();
        return Message::REQUEUE;
    }
);
```

The consume method will wait for a message and call the callback function to process the message.
If there is no message in the queue, the method will wait until a message arrives.

If you want to exit the consume method, just return `Message::ACK | Message::EXIT` from the callback function.

Possible return values from the callback function:

* `Message::ACK` - Acknowledge the message and remove from the queue
* `Message::NACK` - Not acknowledge the message and remove from the queue. If the queue has a dead letter queue, the message will be sent to the dead letter queue.
* `Message::REQUEUE` - Requeue the message
* `Message::EXIT` - Exit the consume method


## RabbitMQ Client (AMQP Protocol)

The RabbitMQ connector uses the [php-amqplib](https://github.com/php-amqplib/php-amqplib) library.

The standard behavior of the connector is to create an Exchange, a Queue and bind the queue to the exchange with a routing key (by default is the same as the queue name).
All messages are published to the exchange and consumed from the queue.

As the queue and exchange is created by the Connector it is recommended you do not use to publish/consume from existing queues.
If you use an existing Queue you might get the error:

```text
PHP Fatal error:  Uncaught PhpAmqpLib\Exception\AMQPProtocolChannelException: PRECONDITION_FAILED - Existing queue 'test' declared with other arguments in AMQPChannel.php:224
```

You can change the behavior of the connection by using the `Pipe::withProperty()` and `Message::withProperty()` methods.
Some of them are used by the RabbitMQConnector by setting some default values:

* `Pipe::withProperty(RabbitMQConnector::EXCHANGE)` - Set the exchange name. Default is the queue name.
* `Pipe::withProperty(RabbitMQConnector::ROUTING_KEY)` - Set the routing key. Default is the queue name.
* `Pipe::withProperty('x-message-ttl')` - Only affects dead letter queues. Set the time to live of the message in milliseconds. Default 3 days.
* `Pipe::withProperty('x-expires')` - Only affects dead letter queues. Set the time to live of the queue in milliseconds. Default 3 days.
* `Message::withProperty('content_type')` - Set the content type of the message. Default is text/plain.
* `Message::withProperty('delivery_mode')` - Set the delivery mode of the message. Default is 2 (persistent).
* `Message::withProperty('priority')` - Set the priority of the message. Values range from 0 to 255, but it's recommended to use 0-10 for performance. Higher values have higher priority.
* `Pipe::withProperty('x-max-priority')` - Set the maximum priority level for the queue. Required for priority queues to work.

### Priority Queues

RabbitMQ supports message prioritization, allowing higher priority messages to be delivered before lower priority ones. To use priority queues:

1. **Declare the queue with a maximum priority level**:
   ```php
   $pipe = new Pipe("priority_queue");
   $pipe->withProperty('x-max-priority', 10); // Set max priority to 10
   ```

2. **Set priority on individual messages**:
   ```php
   $highPriorityMessage = new Message("High Priority Message");
   $highPriorityMessage->withProperty('priority', 10); // Highest priority

   $mediumPriorityMessage = new Message("Medium Priority Message");
   $mediumPriorityMessage->withProperty('priority', 5); // Medium priority

   $lowPriorityMessage = new Message("Low Priority Message");
   $lowPriorityMessage->withProperty('priority', 1); // Low priority
   ```

3. **Publish and consume as normal**:
   ```php
   // Messages will be consumed in priority order (highest first)
   $connector->publish(new Envelope($pipe, $lowPriorityMessage));
   $connector->publish(new Envelope($pipe, $highPriorityMessage));
   $connector->publish(new Envelope($pipe, $mediumPriorityMessage));
   ```

**Important notes about RabbitMQ message priorities**:
- The queue MUST be declared with the 'x-max-priority' argument for priorities to work
- Valid priority values range from 0 to 255, but RabbitMQ recommends using 0-10 for performance
- Higher priority values have higher priority (10 is higher priority than 1)
- Messages with the same priority are processed in FIFO order (first in, first out)
- If no priority is set, the message defaults to priority 0 (lowest)

For a complete example, see the included `example_priority_message.php` file.

Protocols:

| Protocol | URI Example                                         | Notes                                |
|----------|-----------------------------------------------------|--------------------------------------|
| AMQP     | amqp://user:pass@host:port/vhost?arg1=...&args=...  | Default port: 5672.                  |
| AMQPS    | amqps://user:pass@host:port/vhost?arg1=...&args=... | Default port: 5671. Required: capath |

### Connection Parameters

The following parameters are available for both AMQP and AMQPS connections:

- **heartbeat**: Interval in seconds to send heartbeat frames to keep the connection alive during periods of inactivity. Default is 30 seconds.
- **connection_timeout**: Timeout in seconds when establishing a new connection. Default is 10 seconds.
- **max_attempts**: Maximum number of reconnection attempts before failing. Default is 10 attempts.
- **pre_fetch**: Controls how many messages the server will deliver before requiring acknowledgements. Default is 0 (no limit).
- **timeout**: Specifies the timeout in seconds for waiting for messages when consuming. Default is 600 seconds.
- **single_run**: When set to 'true', the consumer will exit after one batch of messages instead of continuously waiting. Default is 'false'.

### AMQPS SSL Parameters

The following parameters are available for secure connections via AMQPS:

- **capath**: (Required) Path to the CA certificate directory. This parameter is mandatory for AMQPS connections.
- **local_cert**: Path to the client certificate file.
- **local_pk**: Path to the client private key file.
- **verify_peer**: Enable/disable peer verification (true/false).
- **verify_peer_name**: Enable/disable peer name verification (true/false).
- **passphrase**: The passphrase for the private key.
- **ciphers**: A list of ciphers to use for the encryption.

### Robust Connection Setup

For applications that need to maintain long-lived connections to RabbitMQ, especially in environments with network challenges or when the connection remains idle for extended periods, use the following robust connection settings:

```php
<?php
// Configure the connection with robust settings
$connectionUri = "amqp://$user:$pass@$host:$port/$vhost?heartbeat=30&connection_timeout=10&max_attempts=5&timeout=60";

$connector = ConnectorFactory::create(new Uri($connectionUri));

// Test the connection before proceeding
$rabbitConnector = new RabbitMQConnector();
$rabbitConnector->setUp(new Uri($connectionUri));
if (!$rabbitConnector->testConnection()) {
    die("Failed to connect to RabbitMQ server.\n");
}
```

This configuration:
- Sends heartbeat every 30 seconds to keep the connection alive
- Sets a 10-second timeout when establishing the connection
- Will attempt to reconnect up to 5 times with exponential backoff
- Sets a 60-second timeout for message consumption operations

See the included `example_robust_connection.php` file for a complete implementation.

## Dependencies

```mermaid
flowchart TD
    byjg/rabbitmq-client --> byjg/message-queue-client
    byjg/rabbitmq-client --> php-amqplib/php-amqplib
```

----
[Open source ByJG](http://opensource.byjg.com)
