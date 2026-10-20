<?php

namespace Tests;

use ByJG\MessageQueueClient\Connector\ConnectorFactory;
use ByJG\MessageQueueClient\Connector\ConnectorInterface;
use ByJG\MessageQueueClient\Connector\Pipe;
use ByJG\MessageQueueClient\RedisQueue\RedisQueueConnector;
use ByJG\MessageQueueClient\Envelope;
use ByJG\MessageQueueClient\Message;
use ByJG\Util\Uri;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class RedisQueueConnectorTest extends TestCase
{
    /** @var ConnectorInterface */
    protected $connector;

    protected string $host;

    #[\Override]
    public function setUp(): void
    {
        $host = getenv('REDIS_HOST');
        if (empty($host)) {
            $host = "127.0.0.1";
        }
        $this->host = $host;
        ConnectorFactory::registerConnector(RedisQueueConnector::class);
        $this->connector = ConnectorFactory::create("redis://$host");
    }

    /**
     * phpredis expects [user, pass] for an ACL login. The credentials used to be passed as
     * [pass, user], so a URI with both a username and a password failed with WRONGPASS.
     */
    public function testAuthenticatesWithAclUsernameAndPassword(): void
    {
        $admin = $this->connector->getDriver();
        $admin->rawCommand('ACL', 'SETUSER', 'acl_test_user', 'on', '>acl_test_pass', '~*', '+@all');

        try {
            $connector = ConnectorFactory::create("redis://acl_test_user:acl_test_pass@{$this->host}");

            $this->assertSame('acl_test_user', $connector->getDriver()->rawCommand('ACL', 'WHOAMI'));
        } finally {
            $admin->rawCommand('ACL', 'DELUSER', 'acl_test_user');
        }
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

    private function resetQueues(string ...$names): void
    {
        $driver = $this->connector->getDriver();
        foreach ($names as $name) {
            $driver->del($name);
        }
    }

    public function testTheMessageIsHeldInTheProcessingListWhileTheCallbackRuns(): void
    {
        $this->resetQueues('reliable', 'reliable:processing');
        $driver = $this->connector->getDriver();
        $pipe = new Pipe('reliable');
        $this->connector->publish(new Envelope($pipe, new Message('in_flight')));

        $seen = null;
        $this->connector->consume($pipe, function (Envelope $envelope) use ($driver, &$seen) {
            $seen = [$driver->lRange('reliable', 0, -1), $driver->lRange('reliable:processing', 0, -1)];
            return Message::ACK | Message::EXIT;
        }, function (Envelope $envelope, $ex) {
            throw $ex;
        });

        $this->assertSame([[], ['in_flight']], $seen, 'While processed, the message must be in the processing list only');
        $this->assertSame([], $driver->lRange('reliable:processing', 0, -1));
    }

    public static function outcomes(): array
    {
        return [
            'ACK' => [Message::ACK, [], []],
            'NACK' => [Message::NACK, [], ['outcome']],
            'REQUEUE' => [Message::REQUEUE, ['outcome'], []],
        ];
    }

    #[DataProvider('outcomes')]
    public function testEveryOutcomeLeavesTheProcessingListEmpty(int $result, array $expectedQueue, array $expectedDlq): void
    {
        $this->resetQueues('reliable', 'reliable:processing', 'dlq_reliable');
        $driver = $this->connector->getDriver();
        $pipe = new Pipe('reliable');
        $pipe->withDeadLetter(new Pipe('dlq_reliable'));
        $this->connector->publish(new Envelope($pipe, new Message('outcome')));

        $this->connector->consume(
            $pipe,
            fn(Envelope $envelope) => $result | Message::EXIT,
            fn(Envelope $envelope, $ex) => throw $ex
        );

        $this->assertSame([], $driver->lRange('reliable:processing', 0, -1));
        $this->assertSame($expectedQueue, $driver->lRange('reliable', 0, -1));
        $this->assertSame($expectedDlq, $driver->lRange('dlq_reliable', 0, -1));
    }

    /**
     * The regression this change exists for. BRPOP removed the message from Redis before the
     * callback ran, so a consumer that died mid-processing lost it. Here the "crash" is an
     * exception escaping consume(), which skips everything after the callback just as a killed
     * process would.
     */
    public function testAMessageSurvivesAConsumerThatDiesMidProcessing(): void
    {
        $this->resetQueues('reliable', 'reliable:processing');
        $driver = $this->connector->getDriver();
        $pipe = new Pipe('reliable');
        $this->connector->publish(new Envelope($pipe, new Message('survivor')));

        try {
            $this->connector->consume(
                $pipe,
                fn(Envelope $envelope) => throw new RuntimeException('consumer died'),
                fn(Envelope $envelope, $ex) => throw $ex
            );
            $this->fail('The consumer was expected to die');
        } catch (RuntimeException $ex) {
            $this->assertSame('consumer died', $ex->getMessage());
        }

        $this->assertSame(['survivor'], $driver->lRange('reliable:processing', 0, -1), 'The message must not be lost');

        // A new consumer recovers it on start.
        $received = null;
        ConnectorFactory::create("redis://{$this->host}")->consume(
            $pipe,
            function (Envelope $envelope) use (&$received) {
                $received = $envelope->getMessage()->getBody();
                return Message::ACK | Message::EXIT;
            },
            fn(Envelope $envelope, $ex) => throw $ex
        );

        $this->assertSame('survivor', $received);
        $this->assertSame([], $driver->lRange('reliable:processing', 0, -1));
        $this->assertSame([], $driver->lRange('reliable', 0, -1));
    }

    public function testRecoveryPutsLeftoversBackAheadOfNewMessagesInTheirOriginalOrder(): void
    {
        $this->resetQueues('reliable', 'reliable:processing');
        $driver = $this->connector->getDriver();
        $pipe = new Pipe('reliable');

        // What a dead consumer leaves after taking first_taken and then second_taken.
        $driver->lPush('reliable:processing', 'first_taken', 'second_taken');
        $this->connector->publish(new Envelope($pipe, new Message('published_later')));

        $received = [];
        $this->connector->consume(
            $pipe,
            function (Envelope $envelope) use (&$received) {
                $received[] = $envelope->getMessage()->getBody();
                return count($received) === 3 ? Message::ACK | Message::EXIT : Message::ACK;
            },
            fn(Envelope $envelope, $ex) => throw $ex
        );

        $this->assertSame(['first_taken', 'second_taken', 'published_later'], $received);
        $this->assertSame([], $driver->lRange('reliable:processing', 0, -1));
    }

    /**
     * With an identification each consumer has its own processing list, so starting one consumer
     * does not take over the in-flight messages of another one still running.
     */
    public function testAConsumerOnlyRecoversItsOwnProcessingList(): void
    {
        $this->resetQueues('reliable', 'reliable:processing:worker-1', 'reliable:processing:worker-2');
        $driver = $this->connector->getDriver();
        $pipe = new Pipe('reliable');

        $driver->lPush('reliable:processing:worker-1', 'in_flight_of_worker_1');
        $this->connector->publish(new Envelope($pipe, new Message('fresh')));

        $receive = function (string $identification): ?string {
            $received = null;
            ConnectorFactory::create("redis://{$this->host}")->consume(
                new Pipe('reliable'),
                function (Envelope $envelope) use (&$received) {
                    $received = $envelope->getMessage()->getBody();
                    return Message::ACK | Message::EXIT;
                },
                fn(Envelope $envelope, $ex) => throw $ex,
                $identification
            );
            return $received;
        };

        $this->assertSame('fresh', $receive('worker-2'));
        $this->assertSame(['in_flight_of_worker_1'], $driver->lRange('reliable:processing:worker-1', 0, -1));

        // worker-1 restarting under the same identification gets its message back.
        $this->assertSame('in_flight_of_worker_1', $receive('worker-1'));
        $this->assertSame([], $driver->lRange('reliable:processing:worker-1', 0, -1));
        $this->assertSame([], $driver->lRange('reliable:processing:worker-2', 0, -1));
    }
}
