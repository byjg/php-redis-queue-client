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
            $username = $this->uri->getUsername();
            $authCredentials = [$password];
            if ($username !== null && $username !== '') {
                $authCredentials[] = $username;
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
     * @throws RedisException
     */
    #[\Override]
    public function consume(Pipe $pipe, Closure $onReceive, Closure $onError, ?string $identification = null): void
    {
        $pipe = clone $pipe;

        while (true) {

            // Loop stops and waits here until a job becomes available
            $message = $this->awaitMessage($pipe);

            $envelope = new Envelope($pipe, new Message($message[1]));

            try {
                $result = $onReceive($envelope);
            } catch (Exception|Error $ex) {
                $result = $onError($envelope, $ex);
            }

            $deadLetter = $pipe->getDeadLetter();
            if (($result & Message::NACK) == Message::NACK && $deadLetter !== null) {
                $dlqEnvelope = new Envelope($deadLetter, new Message($envelope->getMessage()->getBody()));
                $this->publish($dlqEnvelope);
            }

            if (($result & Message::REQUEUE) == Message::REQUEUE) {
                $this->publish($envelope);
            }

            if (($result & Message::EXIT) == Message::EXIT) {
                break;
            }
        }
    }

    /**
     * Block until a message arrives, surviving the broker going away.
     *
     * `brpop` is called with a timeout of 0, so it blocks indefinitely and never returns "nothing
     * yet". Anything other than a message therefore means the connection is gone — either as a
     * `false` return or as a thrown `RedisException`, depending on how the failure surfaces.
     *
     * Both used to end badly. The `false` branch looped straight back into `brpop` on the same dead
     * connection, spinning at full CPU for as long as the broker was down; the exception escaped
     * `consume()` and killed the consumer outright. Neither reconnected, because the driver was
     * fetched once before the loop and never refreshed.
     *
     * @return array The raw brpop reply: element 0 is the queue name, element 1 the payload.
     */
    protected function awaitMessage(Pipe $pipe): array
    {
        $delayMicros = static::RECONNECT_INITIAL_DELAY_MICROS;

        while (true) {
            try {
                $message = $this->popBlocking($pipe);

                if (is_array($message) && array_key_exists(1, $message)) {
                    return $message;
                }
            } catch (RedisException) {
                // Same cause as a false return; handled by the backoff below.
            }

            $this->disconnect();
            $this->sleepMicros($this->delayWithJitter($delayMicros));

            $delayMicros = (int)min($delayMicros * 2, static::RECONNECT_MAX_DELAY_MICROS);
        }
    }

    /**
     * The blocking pop itself. The driver is re-resolved on every call, so a reconnection
     * performed by the caller actually takes effect rather than reusing a dead handle.
     *
     * @return mixed The raw brpop reply: an array on success, false or an exception otherwise.
     * @throws RedisException
     */
    protected function popBlocking(Pipe $pipe): mixed
    {
        return $this->getDriver()->brpop($pipe->getName(), 0);
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

