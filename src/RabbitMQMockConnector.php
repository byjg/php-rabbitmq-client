<?php

namespace ByJG\MessageQueueClient\RabbitMQ;

use ByJG\MessageQueueClient\Connector\ConnectorInterface;
use ByJG\MessageQueueClient\Connector\Pipe;
use ByJG\MessageQueueClient\Envelope;
use ByJG\MessageQueueClient\Message;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPSSLConnection;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Exchange\AMQPExchangeType;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;

class RabbitMQMockConnector implements ConnectorInterface
{
    public static $mockedConnections = [];

    public static function schema()
    {
        return ["amqpmock"];
    }


    /** @var \ByJG\Util\Uri */
    protected $uri;

    public function setUp(\ByJG\Util\Uri $uri)
    {
        $this->uri = $uri;
    }

    /**
     * @return \PhpAmqpLib\Connection\AMQPStreamConnection|\PhpAmqpLib\Connection\AMQPSSLConnection|string
     */
    public function getConnection()
    {
        $hash = md5(trim(strval($this->uri), "/"));
        if (!isset(self::$mockedConnections[$hash])) {
            self::$mockedConnections[$hash] = [];
        }
        return $hash;
    }

    public function publish(Envelope $envelope)
    {
        self::$mockedConnections[$this->getConnection()][$envelope->getPipe()->getName()][] = $envelope;
    }

    public function consume(Pipe $pipe, \Closure $onReceive, \Closure $onError, $identification = null)
    {
        $pipe = clone $pipe;

        $closure = function () use ($onReceive, $onError, $pipe) {
            $envelope = array_shift(self::$mockedConnections[$this->getConnection()][$pipe->getName()]);
            try {
                return $onReceive($envelope);
            } catch (\Exception | \Error $ex) {
                return $onError($envelope, $ex);
            }
        };

        echo $closure();
    }

}

