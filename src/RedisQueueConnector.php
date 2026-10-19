<?php

namespace ByJG\MessageQueueClient\RedisQueue;

use ByJG\MessageQueueClient\Connector\ConnectorInterface;
use ByJG\MessageQueueClient\Connector\Pipe;
use ByJG\MessageQueueClient\Envelope;
use ByJG\MessageQueueClient\Message;

use ByJG\Util\Uri;
use Closure;
use Error;
use Exception;
use Redis;
use RedisException;

class RedisQueueConnector implements ConnectorInterface
{
    /** First pause after losing the broker, in microseconds. */
    protected const RECONNECT_INITIAL_DELAY_MICROS = 200_000;

    /** Ceiling for the pause between reconnection attempts, in microseconds. */
    protected const RECONNECT_MAX_DELAY_MICROS = 30_000_000;

    #[\Override]
    public static function schema(): array
    {
        return ["redis"];
    }

    /** @var Uri */
    protected Uri $uri;

    protected ?Redis $redis = null;

    #[\Override]
    public function setUp(Uri $uri): void
    {
        $this->uri = $uri;
    }


    /**
     * @throws RedisException
     */
    protected function lazyLoadRedisServer(): Redis
    {
        $this->redis = new Redis();
        $this->redis->connect($this->uri->getHost(), $this->uri->getPort() ?? 6379);

        $password = $this->uri->getPassword();
        if ($password !== null && $password !== '') {
            // phpredis takes [user, pass] for an ACL login and [pass] for the default user.
            $username = $this->uri->getUsername();
            $authCredentials = [$password];
            if ($username !== null && $username !== '') {
                $authCredentials = [$username, $password];
            }
            $this->redis->auth($authCredentials);
        }
        $this->redis->setOption(Redis::OPT_SERIALIZER, Redis::SERIALIZER_NONE);

        $this->redis->info('redis_version');

        return $this->redis;
    }

    /**
     * @return Redis
     * @throws RedisException
     */
    #[\Override]
    public function getDriver(): Redis
    {
        if (empty($this->redis)) {
            return $this->lazyLoadRedisServer();
        }

        return $this->redis;
    }

    /**
     * @throws RedisException
     */
    #[\Override]
    public function publish(Envelope $envelope): void
    {
        $properties = $envelope->getMessage()->getProperties();
        $properties['content_type'] ??= 'text/plain';

        $pipe = clone $envelope->getPipe();

        $body = $envelope->getMessage()->getBody();

        $this->getDriver()->lpush($pipe->getName(), $body);

    }

    /**
     * Messages are taken with BLMOVE into a processing list and removed from it only once their
     * outcome (ACK, NACK to the dead letter queue, REQUEUE) is done. A consumer that dies in between
     * leaves the message there, and the next consume() on the same processing list puts it back on
     * the queue. Delivery is therefore at-least-once: after a crash a message can be processed twice.
     *
     * @throws RedisException
     */
    #[\Override]
    public function consume(Pipe $pipe, Closure $onReceive, Closure $onError, ?string $identification = null): void
    {
        $pipe = clone $pipe;
        $processing = $this->processingListName($pipe, $identification);

        $this->retryOnConnectionLoss(fn() => $this->recoverProcessing($pipe, $processing));

        while (true) {

            // Loop stops and waits here until a job becomes available
            $body = $this->awaitMessage($pipe, $processing);

            $envelope = new Envelope($pipe, new Message($body));

            try {
                $result = $onReceive($envelope);
            } catch (Exception|Error $ex) {
                $result = $onError($envelope, $ex);
            }

            // A failed publish is retried rather than dropping the message along with the consumer.
            $deadLetter = $pipe->getDeadLetter();
            if (($result & Message::NACK) == Message::NACK && $deadLetter !== null) {
                $dlqEnvelope = new Envelope($deadLetter, new Message($envelope->getMessage()->getBody()));
                $this->retryOnConnectionLoss(fn() => $this->publish($dlqEnvelope));
            }

            if (($result & Message::REQUEUE) == Message::REQUEUE) {
                $this->retryOnConnectionLoss(fn() => $this->publish($envelope));
            }

            // Only now is the outcome safe, so only now does the message leave the processing list.
            $this->retryOnConnectionLoss(fn() => $this->removeFromProcessing($processing, $body));

            if (($result & Message::EXIT) == Message::EXIT) {
                break;
            }
        }
    }

    /**
     * The list holding the messages this consumer has taken but not finished. Without an
     * identification every consumer of the queue shares `<queue>:processing`; with one, each
     * consumer gets its own `<queue>:processing:<identification>`.
     */
    protected function processingListName(Pipe $pipe, ?string $identification): string
    {
        $name = $pipe->getName() . ':processing';
        if ($identification !== null && $identification !== '') {
            $name .= ':' . $identification;
        }

        return $name;
    }

    /**
     * Put back on the queue whatever a previous consumer left in the processing list. Each element
     * moves with one atomic LMOVE, so a crash here loses nothing. They go to the end the queue is
     * read from, so they are the next ones delivered, in their original order.
     *
     * @throws RedisException
     */
    protected function recoverProcessing(Pipe $pipe, string $processing): void
    {
        $driver = $this->getDriver();
        while ($driver->lmove($processing, $pipe->getName(), 'LEFT', 'RIGHT') !== false) {
            // Keep moving until the processing list is empty.
        }
    }

    /**
     * @throws RedisException
     */
    protected function removeFromProcessing(string $processing, string $body): void
    {
        $this->getDriver()->lrem($processing, $body, 1);
    }

    /**
     * Block until a message arrives, surviving the broker going away.
     *
     * `blmove` is called with a timeout of 0, so it blocks indefinitely and never returns "nothing
     * yet". Anything other than a message therefore means the connection is gone — either as a
     * `false` return or as a thrown `RedisException`, depending on how the failure surfaces.
     *
     * Both used to end badly. The `false` branch looped straight back into the pop on the same dead
     * connection, spinning at full CPU for as long as the broker was down; the exception escaped
     * `consume()` and killed the consumer outright. Neither reconnected, because the driver was
     * fetched once before the loop and never refreshed.
     *
     * @return string The message body, now also held in the processing list.
     */
    protected function awaitMessage(Pipe $pipe, string $processing): string
    {
        /** @var string */
        return $this->retryOnConnectionLoss(function () use ($pipe, $processing): string {
            $message = $this->popBlocking($pipe, $processing);

            if (is_string($message)) {
                return $message;
            }

            // Same cause as a thrown exception: the connection is gone.
            throw new RedisException('blmove returned no message');
        });
    }

    /**
     * Run an operation until it completes, treating a `RedisException` as a lost connection:
     * drop the connection, pause with a growing backoff, and try again. The backoff starts over on
     * every call, so it resets after each success.
     *
     * @throws Exception|Error Anything the operation throws other than a RedisException.
     */
    protected function retryOnConnectionLoss(Closure $operation): mixed
    {
        $delayMicros = static::RECONNECT_INITIAL_DELAY_MICROS;

        while (true) {
            try {
                return $operation();
            } catch (RedisException) {
                // Handled by the backoff below.
            }

            $this->disconnect();
            $this->sleepMicros($this->delayWithJitter($delayMicros));

            $delayMicros = (int)min($delayMicros * 2, static::RECONNECT_MAX_DELAY_MICROS);
        }
    }

    /**
     * The blocking pop itself: atomically moves the oldest message of the queue into the processing
     * list (BLMOVE, Redis >= 6.2). The driver is re-resolved on every call, so a reconnection
     * performed by the caller actually takes effect rather than reusing a dead handle.
     *
     * @return mixed The raw blmove reply: the message body on success, false or an exception otherwise.
     * @throws RedisException
     */
    protected function popBlocking(Pipe $pipe, string $processing): mixed
    {
        return $this->getDriver()->blmove($pipe->getName(), $processing, 'RIGHT', 'LEFT', 0);
    }

    /**
     * Drop the current connection so the next getDriver() call builds a fresh one.
     */
    protected function disconnect(): void
    {
        try {
            $this->redis?->close();
        } catch (RedisException) {
            // Already gone; nothing to close.
        }

        $this->redis = null;
    }

    /**
     * Equal jitter: half the delay is guaranteed, the rest is random.
     *
     * Without randomness every consumer that lost the same broker reconnects at the same instant,
     * and the burst lands on a server that has only just come back up. Equal jitter rather than
     * full jitter because the guaranteed half is what rules out the tight spin this method exists
     * to prevent — a full-jitter draw near zero would reproduce it.
     */
    protected function delayWithJitter(int $delayMicros): int
    {
        $half = intdiv($delayMicros, 2);

        return max(1, $half + random_int(0, $half));
    }

    /**
     * Seam for tests, which cannot afford to actually wait out the backoff.
     */
    protected function sleepMicros(int $micros): void
    {
        usleep($micros);
    }
}

