# RabbitMQ Client

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

You can change the behavior of the connection by using the `Pipe::withProperty()` and `Message::withHeader()` methods.
Some of them are used by the RabbitMQConnector by setting some default values:

* `Pipe::withProperty(RabbitMQConnector::EXCHANGE)` - Set the exchange name. Default is the queue name.
* `Pipe::withProperty(RabbitMQConnector::ROUTING_KEY)` - Set the routing key. Default is the queue name.
* `Pipe::withProperty('x-message-ttl')` - Only affects dead letter queues. Set the time to live of the message in milliseconds. Default 3 days.
* `Pipe::withProperty('x-expires')` - Only affects dead letter queues. Set the time to live of the queue in milliseconds. Default 3 days.
* `Message::withHeader('content_type')` - Set the content type of the message. Default is text/plain.
* `Message::withHeader('delivery_mode')` - Set the delivery mode of the message. Default is 2 (persistent).

Protocols:

| Protocol | URI Example                                         | Notes |
| -------- | --------------------------------------------------- | ----- |
| AMQP     | amqp://user:pass@host:port/vhost                    | Default port: 5672.     |
| AMQPS    | amqps://user:pass@host:port/vhost?arg1=...&args=... | Default port: 5671. Args: capath*, local_cert, local_pk, verify_peer, verify_peer_name |

----
[Open source ByJG](http://opensource.byjg.com)
