<?php


use ByJG\MessageQueueClient\Connector\ConnectorFactory;
use ByJG\MessageQueueClient\Message;
use ByJG\MessageQueueClient\RabbitMQ\RabbitMQMockConnector;
use ByJG\Util\Uri;
use PHPUnit\Framework\TestCase;

class RabbitMQMockConnectorTest extends TestCase
{
    public function testPublishConsume()
    {
        ConnectorFactory::registerConnector(RabbitMQMockConnector::class);
        $connector = ConnectorFactory::create(new Uri("amqpmock://local"));

        $this->assertInstanceOf(RabbitMQMockConnector::class, $connector);

        $pipe = new \ByJG\MessageQueueClient\Connector\Pipe("test");
        $message = new Message("body");
        $connector->publish(new \ByJG\MessageQueueClient\Envelope($pipe, $message));

        $connector->consume($pipe, function (\ByJG\MessageQueueClient\Envelope $envelope) {
            $this->assertEquals("body", $envelope->getMessage()->getBody());
            return Message::ACK;
        }, function () {
            $this->assertTrue(false);
            return Message::NACK;
        });
    }


}