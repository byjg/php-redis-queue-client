<?php

namespace ByJG\MessageQueueClient\RedisQueue;

use ByJG\MessageQueueClient\Connector\ConnectorInterface;
use ByJG\MessageQueueClient\Connector\Pipe;
use ByJG\MessageQueueClient\Envelope;
use ByJG\MessageQueueClient\Message;

use Redis;

class RedisQueueConnector implements ConnectorInterface
{
    public static function schema()
    {
        return ["redis"];
    }

    /** @var \ByJG\Util\Uri */
    protected $uri;

    protected $redis;

    public function setUp(\ByJG\Util\Uri $uri)
    {
        $this->uri = $uri;
    }


    protected function lazyLoadRedisServer()
    {
        $this->redis = new Redis();
        $this->redis->connect($this->uri->getHost(), empty($this->uri->getPort()) ? 6379 : $this->uri->getPort());

        if (!empty($this->uri->getPassword())) {
            $password = [ $this->uri->getPassword() ];
            if (!empty($this->uri->getUsername())) {
                $password[] = $this->uri->getUsername();
            }
            $this->redis->auth($password);
        }
        $this->redis->setOption(Redis::OPT_SERIALIZER, Redis::SERIALIZER_NONE);

        $this->redis->info('redis_version');
    }

    /**
     * @return Redis
     */
    public function getDriver()
    {
        if (empty($this->redis)) {
            $this->lazyLoadRedisServer();
        }

        return $this->redis;
    }

    public function publish(Envelope $envelope)
    {
        $properties = $envelope->getMessage()->getProperties();
        $properties['content_type'] = $properties['content_type'] ?? 'text/plain';

        $pipe = clone $envelope->getPipe();

        $body = $envelope->getMessage()->getBody();

        $this->getDriver()->lpush($pipe->getName(), $body);

    }

    public function consume(Pipe $pipe, \Closure $onReceive, \Closure $onError, $identification = null)
    {
        $pipe = clone $pipe;

        $driver = $this->getDriver();

        while (true) {

            // Loop stops and waits here until a job becomes available
            $message = $driver->brpop($pipe->getName(), 0);

            $envelope = new Envelope($pipe, new Message($message[1]));

            try {
                $result = $onReceive($envelope);
            } catch (\Exception|\Error $ex) {
                $result = $onError($envelope, $ex);
            }

            if (($result & Message::NACK) == Message::NACK && $pipe->getDeadLetter() !== null) {
                $dlqEnvelope = new Envelope($pipe->getDeadLetter(), new Message($envelope->getMessage()->getBody()));
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
}

