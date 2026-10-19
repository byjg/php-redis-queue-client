<?php

namespace Tests;

use ByJG\MessageQueueClient\Connector\Pipe;
use ByJG\MessageQueueClient\Envelope;
use ByJG\MessageQueueClient\Message;
use ByJG\MessageQueueClient\RedisQueue\RedisQueueConnector;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Scripts the outcome of each blocking pop so the reconnection loop can be driven through failures
 * that are impractical to stage against a live broker.
 *
 * The seam is `popBlocking()` rather than the Redis object, so no part of phpredis is faked - what
 * is under test is how the loop reacts to a failed pop, not how phpredis reports one. Sleeps are
 * recorded instead of taken, so a 30-second backoff ceiling is verifiable in microseconds.
 */
class ScriptedConnector extends RedisQueueConnector
{
    /** @var list<int> Microsecond values passed to sleep, in order. */
    public array $sleeps = [];

    public int $disconnects = 0;

    /** @var list<string> Queued outcomes, consumed one per pop: 'throw', 'false' or a payload. */
    public array $outcomes = [];

    /** @var int How many of the next publishes fail with a lost connection. */
    public int $failingPublishes = 0;

    /** @var list<array{string, string}> Queue name and body of every publish that went through. */
    public array $published = [];

    public function awaitMessagePublicly(Pipe $pipe): array
    {
        return $this->awaitMessage($pipe);
    }

    public function jitterPublicly(int $delayMicros): int
    {
        return $this->delayWithJitter($delayMicros);
    }

    #[\Override]
    protected function popBlocking(Pipe $pipe): mixed
    {
        $outcome = array_shift($this->outcomes);

        if ($outcome === 'throw') {
            throw new \RedisException('connection lost');
        }

        if ($outcome === 'false') {
            return false;
        }

        return [$pipe->getName(), $outcome];
    }

    #[\Override]
    public function publish(Envelope $envelope): void
    {
        if ($this->failingPublishes > 0) {
            $this->failingPublishes--;
            throw new \RedisException('connection lost');
        }

        $this->published[] = [$envelope->getPipe()->getName(), $envelope->getMessage()->getBody()];
    }

    #[\Override]
    protected function disconnect(): void
    {
        $this->disconnects++;
    }

    #[\Override]
    protected function sleepMicros(int $micros): void
    {
        $this->sleeps[] = $micros;
    }
}

class ReconnectTest extends TestCase
{
    private ScriptedConnector $connector;
    private Pipe $pipe;

    #[\Override]
    public function setUp(): void
    {
        $this->connector = new ScriptedConnector();
        $this->pipe = new Pipe('reconnect_test');
    }

    public function testAHealthyPopReturnsImmediatelyWithoutSleeping(): void
    {
        $this->connector->outcomes = ['payload'];

        $message = $this->connector->awaitMessagePublicly($this->pipe);

        $this->assertSame(['reconnect_test', 'payload'], $message);
        $this->assertSame([], $this->connector->sleeps, 'A healthy pop must not pause at all');
        $this->assertSame(0, $this->connector->disconnects);
    }

    /**
     * The regression this change exists for. brpop is called with a timeout of 0, so it blocks
     * indefinitely and never reports "no message yet" - a false reply means the connection died.
     * The old loop went straight back into brpop on that same dead handle, spinning at full CPU for
     * as long as the broker stayed down.
     */
    public function testAFalseReplyBacksOffAndReconnectsInsteadOfSpinning(): void
    {
        $this->connector->outcomes = ['false', 'false', 'payload'];

        $message = $this->connector->awaitMessagePublicly($this->pipe);

        $this->assertSame('payload', $message[1]);
        $this->assertCount(2, $this->connector->sleeps, 'Every failed pop must pause before retrying');
        $this->assertSame(2, $this->connector->disconnects, 'Every failed pop must drop the dead connection');
    }

    /**
     * The other half of the same bug: a RedisException escaped consume() entirely and killed the
     * consumer, rather than being treated as "the broker went away, wait and reconnect".
     */
    public function testAThrownRedisExceptionIsTreatedAsALostConnection(): void
    {
        $this->connector->outcomes = ['throw', 'throw', 'throw', 'payload'];

        $message = $this->connector->awaitMessagePublicly($this->pipe);

        $this->assertSame('payload', $message[1]);
        $this->assertCount(3, $this->connector->sleeps);
        $this->assertSame(3, $this->connector->disconnects);
    }

    /**
     * A broker that is down for minutes must not be probed every 200ms forever, and the growth has
     * to stop somewhere.
     */
    public function testTheBackoffGrowsAndIsCapped(): void
    {
        $this->connector->outcomes = array_fill(0, 12, 'throw');
        $this->connector->outcomes[] = 'payload';

        $this->connector->awaitMessagePublicly($this->pipe);

        foreach ($this->connector->sleeps as $i => $slept) {
            $computed = (int)min(200_000 * (2 ** (int)$i), 30_000_000);

            // Equal jitter: at least half the computed delay, never more than all of it.
            $this->assertGreaterThanOrEqual(intdiv($computed, 2), $slept, "Sleep $i fell below its floor");
            $this->assertLessThanOrEqual($computed, $slept, "Sleep $i exceeded its computed delay");
        }

        $this->assertLessThanOrEqual(
            30_000_000,
            max($this->connector->sleeps),
            'The backoff must be capped rather than growing without bound'
        );
    }

    /**
     * The guaranteed minimum is what actually rules out the spin, so it is asserted directly rather
     * than inferred. Full jitter would allow a draw near zero and reproduce the original bug.
     */
    public function testJitterAlwaysLeavesANonZeroPause(): void
    {
        for ($i = 0; $i < 500; $i++) {
            $this->assertGreaterThan(0, $this->connector->jitterPublicly(1));
            $this->assertGreaterThanOrEqual(100_000, $this->connector->jitterPublicly(200_000));
            $this->assertLessThanOrEqual(200_000, $this->connector->jitterPublicly(200_000));
        }
    }

    public function testJitterActuallyVariesTheDelay(): void
    {
        $draws = [];
        for ($i = 0; $i < 50; $i++) {
            $draws[] = $this->connector->jitterPublicly(200_000);
        }

        $this->assertGreaterThan(1, count(array_unique($draws)), 'The pause is not being jittered at all');
    }

    /**
     * Backoff state must not leak between messages. A consumer that rode out an outage an hour ago
     * should react to the next failure in 200ms, not sit on the 30-second ceiling it reached then.
     */
    public function testTheBackoffResetsAfterASuccessfulMessage(): void
    {
        $this->connector->outcomes = ['throw', 'throw', 'payload'];
        $this->connector->awaitMessagePublicly($this->pipe);
        $firstOutage = $this->connector->sleeps;

        $this->connector->sleeps = [];
        $this->connector->outcomes = ['throw', 'throw', 'payload'];
        $this->connector->awaitMessagePublicly($this->pipe);

        $this->assertCount(count($firstOutage), $this->connector->sleeps);
        $this->assertLessThanOrEqual(
            200_000,
            $this->connector->sleeps[0],
            'The second outage must start again from the initial delay'
        );
    }

    public static function publishingResults(): array
    {
        return [
            'NACK to the dead letter queue' => [Message::NACK, 'reconnect_dlq'],
            'REQUEUE to the same queue' => [Message::REQUEUE, 'reconnect_test'],
        ];
    }

    /**
     * The message has already been popped when NACK or REQUEUE publishes it, so a RedisException
     * there used to escape consume(), kill the consumer and lose the message. It must instead be
     * retried with the same backoff as a lost connection while waiting.
     */
    #[DataProvider('publishingResults')]
    public function testAFailedPublishAfterConsumingIsRetriedNotLost(int $result, string $expectedQueue): void
    {
        $this->pipe->withDeadLetter(new Pipe('reconnect_dlq'));
        $this->connector->outcomes = ['payload'];
        $this->connector->failingPublishes = 2;

        $this->connector->consume(
            $this->pipe,
            fn(Envelope $envelope) => $result | Message::EXIT,
            fn(Envelope $envelope, $ex) => throw $ex
        );

        $this->assertSame([[$expectedQueue, 'payload']], $this->connector->published);
        $this->assertCount(2, $this->connector->sleeps, 'Every failed publish must pause before retrying');
        $this->assertSame(2, $this->connector->disconnects, 'Every failed publish must drop the connection');
    }
}
