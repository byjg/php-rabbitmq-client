<?php

namespace Tests;

use ByJG\MessageQueueClient\RabbitMQ\RabbitMQConnector;
use ByJG\Util\Uri;
use PhpAmqpLib\Connection\AMQPConnectionConfig;
use PHPUnit\Framework\TestCase;

class RabbitMQConnectionConfigTest extends TestCase
{
    protected function config(string $uri): AMQPConnectionConfig
    {
        $connector = new class extends RabbitMQConnector {
            public function config(): AMQPConnectionConfig
            {
                return $this->getConnectionConfig();
            }
        };
        $connector->setUp(new Uri($uri));
        return $connector->config();
    }

    public function testCaPathDirectory(): void
    {
        $config = $this->config("amqps://user:pass@host/?capath=" . __DIR__);

        $this->assertTrue($config->isSecure());
        $this->assertSame(5671, $config->getPort());
        $this->assertSame(__DIR__, $config->getSslCaPath());
        $this->assertNull($config->getSslCaCert());
    }

    public function testCaPathFile(): void
    {
        $config = $this->config("amqps://user:pass@host/?capath=" . __FILE__);

        $this->assertSame(__FILE__, $config->getSslCaCert());
        $this->assertNull($config->getSslCaPath());
    }

    public function testClientCertificate(): void
    {
        $config = $this->config("amqps://user:pass@host/?capath=" . __DIR__ . "&local_cert=/certs/client.pem&local_pk=/certs/client.key");

        $this->assertSame('/certs/client.pem', $config->getSslCert());
        $this->assertSame('/certs/client.key', $config->getSslKey());
        $this->assertSame(__DIR__, $config->getSslCaPath());
    }

    public function testVerifyPeerByDefault(): void
    {
        $config = $this->config("amqps://user:pass@host/?capath=" . __DIR__);

        $this->assertTrue($config->getSslVerify());
        $this->assertTrue($config->getSslVerifyName());
    }

    public function testVerifyPeerDisabled(): void
    {
        $config = $this->config("amqps://user:pass@host/?capath=" . __DIR__ . "&verify_peer=false&verify_peer_name=false");

        $this->assertFalse($config->getSslVerify());
        $this->assertFalse($config->getSslVerifyName());
    }

    public function testCaPathRequired(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->config("amqps://user:pass@host/");
    }

    public function testAmqpIsNotSecure(): void
    {
        $config = $this->config("amqp://user:pass@host/");

        $this->assertFalse($config->isSecure());
        $this->assertSame(5672, $config->getPort());
    }
}
