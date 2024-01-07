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
use PhpAmqpLib\Connection\AMQPSSLConnection;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Exchange\AMQPExchangeType;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;

class RabbitMQConnector implements ConnectorInterface
{
    const ROUTING_KEY = '_x_routing_key';
    const EXCHANGE = '_x_exchange';

    const PARAM_CAPATH = 'capath';

    public static function schema(): array
    {
        return ["amqp", "amqps"];
    }

    /** @var Uri */
    protected Uri $uri;

    public function setUp(Uri $uri): void
    {
        $this->uri = $uri;
    }

    /**
     * @return AMQPStreamConnection|AMQPSSLConnection
     * @throws Exception
     */
    public function getDriver(): AMQPStreamConnection|AMQPSSLConnection
    {
        $vhost = trim($this->uri->getPath(), "/");
        if (empty($vhost)) {
            $vhost = "/";
        }

        $args = [];
        if (!empty($this->uri->getQuery())) {
            parse_str($this->uri->getQuery(), $args);
        }

        if ($this->uri->getScheme() == "amqps") {
            $port = 5671;
            if (empty($args[self::PARAM_CAPATH])) {
                throw new InvalidArgumentException("The 'capath' parameter is required for AMQPS");
            }

            $driver = new AMQPSSLConnection(
                $this->uri->getHost(),
                empty($this->uri->getPort()) ? $port : $this->uri->getPort(),
                $this->uri->getUsername(),
                $this->uri->getPassword(),
                $vhost,
                [
                    "ssl" => $args
                ]
            );
        } else {
            $port = 5672;

            $driver = new AMQPStreamConnection(
                $this->uri->getHost(),
                empty($this->uri->getPort()) ? $port : $this->uri->getPort(),
                $this->uri->getUsername(),
                $this->uri->getPassword(),
                $vhost
            );
        }


        return $driver;
    }

    /**
     * @param AMQPSSLConnection|AMQPStreamConnection $connection
     * @param Pipe $pipe
     * @param bool $withExchange
     * @return AMQPChannel
     */
    protected function createQueue(AMQPSSLConnection|AMQPStreamConnection $connection, Pipe $pipe, bool $withExchange = true): AMQPChannel
    {
        $pipe->setPropertyIfNull('exchange_type', AMQPExchangeType::DIRECT);
        $pipe->setPropertyIfNull(self::EXCHANGE, $pipe->getName());
        $pipe->setPropertyIfNull(self::ROUTING_KEY, $pipe->getName());

        $amqpTable = [];
        $dlq = $pipe->getDeadLetter();
        if (!empty($dlq)) {
            $dlq->withProperty('exchange_type', AMQPExchangeType::FANOUT);
            $channelDlq = $this->createQueue($connection, $dlq);
            $channelDlq->close();

            $dlqProperties = $dlq->getProperties();
            $dlqProperties['x-dead-letter-exchange'] = $dlq->getProperty(self::EXCHANGE, $dlq->getName());
            // $dlqProperties['x-dead-letter-routing-key'] = $routingKey;
            // $dlqProperties['x-message-ttl'] = $dlq->getProperty('x-message-ttl', 3600 * 72*1000);
            // $dlqProperties['x-expires'] = $dlq->getProperty('x-expires', 3600 * 72*1000 + 1000);
            $amqpTable = new AMQPTable($dlqProperties);
        }

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
     */
    protected function lazyConnect(Pipe $pipe, $withExchange = true): array
    {
        $driver = $this->getDriver();
        $channel = $this->createQueue($driver, $pipe, $withExchange);

        return [$driver, $channel];
    }


    /**
     * @throws Exception
     */
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

    /**
     * @throws Exception
     */
    public function consume(Pipe $pipe, Closure $onReceive, Closure $onError, $identification = null): void
    {
        $pipe = clone $pipe;

        list($driver, $channel) = $this->lazyConnect($pipe, false);

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

        /*
            pipe: Queue from where to get the messages
            consumer_tag: Consumer identifier
            no_local: Don't receive messages published by this consumer.
            no_ack: If set to true, automatic acknowledgement mode will be used by this consumer. See https://www.rabbitmq.com/confirms.html for details.
            exclusive: Request exclusive consumer access, meaning only this consumer can access the queue
            nowait:
            callback: A PHP Callback
        */
        $channel->basic_consume($pipe->getName(), $identification ?? $pipe->getName(), false, false, false, false, $closure);

        register_shutdown_function(function () use ($channel, $driver) {
            $channel->close();
            $driver->close();
        });

        // Loop as long as the channel has callbacks registered
        $channel->consume();

    }

}

