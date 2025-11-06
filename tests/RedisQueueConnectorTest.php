<?php

use ByJG\MessageQueueClient\Connector\ConnectorFactory;
use ByJG\MessageQueueClient\Connector\ConnectorInterface;
use ByJG\MessageQueueClient\Connector\Pipe;
use ByJG\MessageQueueClient\RedisQueue\RedisQueueConnector;
use ByJG\MessageQueueClient\Envelope;
use ByJG\MessageQueueClient\Message;
use ByJG\Util\Uri;
use PHPUnit\Framework\TestCase;

class RedisQueueConnectorTest extends TestCase
{
    /** @var ConnectorInterface */
    protected $connector;

    #[\Override]
    public function setUp(): void
    {
        $host = getenv('REDIS_HOST');
        if (empty($host)) {
            $host = "127.0.0.1";
        }
        ConnectorFactory::registerConnector(RedisQueueConnector::class);
        $this->connector = ConnectorFactory::create("redis://$host");
    }

    public function testClearQueues(): void
    {
        // We are not using tearDown() because we want to keep the queues for the other tests

        $driver = $this->connector->getDriver();
        $driver->del("test");
        $driver->del("test2");
        $driver->del("dlq_test2");

        $this->assertTrue(true);
    }

    public function testPublishConsume(): void
    {

        $pipe = new Pipe("test");
        $message = new Message("body");
        $this->connector->publish(new Envelope($pipe, $message));

        $this->connector->consume($pipe, function (Envelope $envelope) {
            $this->assertEquals("body", $envelope->getMessage()->getBody());
            $this->assertEquals("test", $envelope->getPipe()->getName());
            $this->assertEquals([], $envelope->getMessage()->getProperties());
            $this->assertEquals([], $envelope->getPipe()->getProperties());
            return Message::ACK | Message::EXIT;
        }, function (Envelope $envelope, $ex) {
            throw $ex;
        });
    }

    public function testPublishConsumeRequeue(): void
    {
        $pipe = new Pipe("test");
        $message = new Message("body_requeue");
        $this->connector->publish(new Envelope($pipe, $message));

        $this->connector->consume($pipe, function (Envelope $envelope) {
            $this->assertEquals("body_requeue", $envelope->getMessage()->getBody());
            $this->assertEquals("test", $envelope->getPipe()->getName());
            $this->assertEquals([], $envelope->getMessage()->getProperties());
            $this->assertEquals([], $envelope->getPipe()->getProperties());
            return Message::REQUEUE | Message::EXIT;
        }, function (Envelope $envelope, $ex) {
            throw $ex;
        });
    }

    public function testConsumeMessageRequeued(): void
    {
        $pipe = new Pipe("test");

        $this->connector->consume($pipe, function (Envelope $envelope) {
            $this->assertEquals("body_requeue", $envelope->getMessage()->getBody());
            $this->assertEquals("test", $envelope->getPipe()->getName());
            $this->assertEquals([], $envelope->getMessage()->getProperties());
            $this->assertEquals([], $envelope->getPipe()->getProperties());
            return Message::ACK | Message::EXIT;
        }, function (Envelope $envelope, $ex) {
            throw $ex;
        });
    }

    public function testPublishConsumeWithDlq(): void
    {
        $pipe = new Pipe("test2");
        $dlqQueue = new Pipe("dlq_test2");
        $pipe->withDeadLetter($dlqQueue);

        // Post and consume a message
        $message = new Message("bodydlq");
        $this->connector->publish(new Envelope($pipe, $message));

        $this->connector->consume($pipe, function (Envelope $envelope) {
            $this->assertEquals("bodydlq", $envelope->getMessage()->getBody());
            $this->assertEquals("test2", $envelope->getPipe()->getName());
            $this->assertEquals([], $envelope->getMessage()->getProperties());
            $this->assertEquals([], $envelope->getPipe()->getProperties());
            return Message::ACK | Message::EXIT;
        }, function (Envelope $envelope, $ex) {
            throw $ex;
        });

        // Post and reject  a message (NACK, to send to the DLQ)
        $message = new Message("bodydlq_2");
        $this->connector->publish(new Envelope($pipe, $message));

        $this->connector->consume($pipe, function (Envelope $envelope) {
            $this->assertEquals("bodydlq_2", $envelope->getMessage()->getBody());
            $this->assertEquals("test2", $envelope->getPipe()->getName());
            $this->assertEquals([], $envelope->getMessage()->getProperties());
            $this->assertEquals([], $envelope->getPipe()->getProperties());
            return Message::NACK | Message::EXIT;
        }, function (Envelope $envelope, $ex) {
            throw $ex;
        });

        // Consume the DLQ
        $this->connector->consume($dlqQueue, function (Envelope $envelope) {
            $this->assertEquals("bodydlq_2", $envelope->getMessage()->getBody());
            $this->assertEquals("dlq_test2", $envelope->getPipe()->getName());
            $properties = $envelope->getMessage()->getProperties();
            $this->assertEquals([], $properties);
            $this->assertEquals([], $envelope->getPipe()->getProperties());
            return Message::NACK | Message::EXIT;
        }, function (Envelope $envelope, $ex) {
            throw $ex;
        });

    }

}
