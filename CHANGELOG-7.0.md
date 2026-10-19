# Changelog - Version 7.0

> **Status: in development.** This document tracks changes landing on the `7.0` branch.
> Nothing here is released yet, and the contents may still change.

## Overview

Version 7.0 fixes a consumer that could neither survive nor recover from the broker going away. A
lost Redis connection either pinned a CPU core at roughly 1.6 million iterations per second or
killed the consumer outright, and in neither case did it ever reconnect. It also stops losing the
message a consumer was handling when that consumer dies: messages are kept in a processing list until
their outcome is done (at-least-once delivery, Redis 6.2 or later).

Part of the coordinated move of the byjg PHP components to 7.0.

## Breaking Changes

| Area | Before (6.x) | After (7.0) | Description |
|------|--------------|-------------|-------------|
| **Lost connections** | `RedisException` escaped `consume()` | caught, and retried indefinitely | A consumer no longer exits when Redis goes away. If you relied on that exception to end the process and have a supervisor restart it, the consumer now rides the outage out instead. |
| **NACK / REQUEUE publish** | `RedisException` escaped `consume()` | caught, and retried indefinitely | The publish to the dead letter queue or back to the queue is retried with the same backoff instead of ending the consumer. |
| **Redis server version** | any (`BRPOP`) | 6.2 or later (`BLMOVE`, `LMOVE`) | `consume()` takes messages with `BLMOVE`. `ext-redis` 6.0 or later is needed for `blmove`/`lmove`. |
| **New keys** | only the queue | `<queue>:processing` or `<queue>:processing:<identification>` | In-flight messages live in a processing list next to the queue. Account for these keys in key-space monitoring, ACL key patterns and cleanup scripts. |
| **Delivery guarantee** | at-most-once (a crash lost the message) | at-least-once | A consumer that dies mid-processing no longer loses the message, but it can be delivered again after recovery. Handlers should be idempotent. |
| **`$identification`** | ignored | names the processing list | When it is set, the consumer uses its own processing list. Consumers of the same queue that do not set it share `<queue>:processing`, and each one that starts puts the in-flight messages of the others back on the queue (see Migration). |
| **Start of `consume()`** | waits for a message | first moves its processing list back to the queue | Messages left by a dead consumer are delivered again before new ones. |

## Bug Fixes

### A consumer that died mid-processing lost the message

`consume()` took the message with `BRPOP`, which removes it from Redis. From then until the callback
finished and the NACK/REQUEUE publish went through, the message existed only in the consumer's
memory: a killed, crashed or out-of-memory process lost it.

Messages are now taken with `BLMOVE`, which atomically moves them into a processing list
(`<queue>:processing`, or `<queue>:processing:<identification>` when `consume()` is given an
identification). The message is removed from it with `LREM` only after the outcome is done: ACK
right away, NACK after the publish to the dead letter queue, REQUEUE after the publish back to the
queue (both publishes keep their retry with backoff). When `consume()` starts, it moves whatever is
left in its processing list back to the queue with one atomic `LMOVE` per message, so those messages
are delivered next, in their original order.

Recovery runs at start and covers the whole processing list of that consumer, which is why the list
is per identification: a consumer that starts must not take the in-flight messages of another one
still running. A processing list whose consumer never returns under the same identification is not
recovered automatically; see [Reliable Delivery](docs/consuming.md#reliable-delivery).

`BLMOVE` was chosen over `BRPOPLPUSH`, which does the same thing but is deprecated since Redis 6.2.

### A lost connection spun the CPU or killed the consumer

`consume()` resolved the driver once, before the loop, and never refreshed it:

```php
$driver = $this->getDriver();

while (true) {
    $message = $driver->brpop($pipe->getName(), 0);

    if ($message === false || $message === null) {
        continue;
    }
```

`brpop` is called with a timeout of `0`, so it blocks indefinitely and never returns "no message
yet". A `false` reply therefore means the connection died — and `continue` went straight back into
`brpop` on that same dead handle. Replaying the loop against an unreachable broker:

```
original loop: 162,981 iterations in 100ms  (1,629,810/sec against a dead broker)
```

When the failure surfaced as a thrown `RedisException` instead, it escaped `consume()` and ended the
consumer. Because `$driver` was captured before the loop, neither path could ever reconnect.

Both outcomes are now treated as the same event — the broker is gone — and handled by dropping the
connection, pausing, and retrying. The driver is re-resolved on every attempt so a reconnection
actually takes effect.

### A failed NACK or REQUEUE publish lost the message

After the callback returned `Message::NACK` (with a dead letter queue) or `Message::REQUEUE`,
`consume()` published the message with a plain `publish()`. The message had already been popped by
`brpop`, so a `RedisException` there escaped `consume()`, killed the consumer and dropped the
message. That publish is now retried with the same backoff as a lost connection while waiting, and
the message is kept in memory until it goes through. A process that dies before then no longer loses
it either: it stays in the processing list (see above).

### Username and password were passed to `AUTH` in the wrong order

For a URI with both a username and a password, the connector called `auth([$password, $username])`.
phpredis expects `[$user, $pass]`, so every ACL login failed with `WRONGPASS`. The order is fixed;
a password without a username is still sent as `[$password]`, which authenticates the `default` user.

### The retry pause

Reconnection backs off from 200ms, doubling to a ceiling of 30 seconds, and resets after each
message that is successfully received — so a consumer that rode out an outage an hour ago still
reacts to the next failure in 200ms rather than sitting on the ceiling it reached back then.

The pause is jittered so consumers that all lost the same broker do not reconnect in lockstep.
Equal jitter specifically: half of the computed delay is guaranteed and the remainder is random. A
fully random delay can draw near zero, which would reproduce the very spin this change exists to
prevent.

## Testing

`tests/ReconnectTest.php` drives the loop through failures that are impractical to stage against a
live broker: a `false` reply, a thrown `RedisException`, and long outages. It asserts that every
failed pop pauses and drops its connection, that the backoff grows and stays capped, that a jittered
pause is never zero, and that the backoff resets after a successful message.
It also drives `consume()` through a NACK and a REQUEUE whose publish fails, and asserts the message
is published once Redis is back rather than lost.

`tests/RedisQueueConnectorTest.php` creates an ACL user on the test Redis and asserts that a
`redis://user:pass@host` URI authenticates as that user.

Against the test Redis it also asserts that a message is in the processing list (and not in the
queue) while the callback runs; that ACK, NACK and REQUEUE all leave the processing list empty; that
a message survives a consumer that dies mid-processing and is delivered by the next consumer; that
recovery puts leftovers back ahead of newer messages in their original order; and that a consumer
with an identification recovers only its own processing list. `ReconnectTest` asserts that the
message leaves the processing list only after a NACK/REQUEUE publish that failed has gone through.

A `popBlocking()` seam was added so those tests script pop outcomes without faking phpredis or
needing a live Redis, and sleeps are recorded rather than taken — so a 30 second ceiling is
verifiable in microseconds.

## Migration Path from 6.x to 7.0

### Step 1: Update the dependency

```bash
composer require byjg/redis-queue-client:^7.0
composer update
```

### Step 2: Check how you handle a dead broker

If a supervisor was restarting consumers that died on `RedisException`, that no longer happens — the
consumer waits and reconnects on its own. This is usually what you want, but it does mean a consumer
stuck against a permanently unreachable broker now stays alive rather than exiting.

### Step 3: Several consumers on one queue? Give each a stable identification

If more than one process consumes the same queue, pass each a distinct identification that stays the
same across restarts (`consume($pipe, $onReceive, $onError, "worker-1")`). Without it they share
`<queue>:processing`, and every consumer that starts re-delivers the messages the others are
processing at that moment.

### Step 4: Make handlers idempotent

Delivery is at-least-once: after a crash a message can be processed again.

## Notes

- `ConnectorInterface` is unchanged; code written against 6.x compiles as is. What changes is
  behaviour: the Redis version, the processing-list keys, at-least-once delivery and the meaning of
  `$identification` (see [Breaking Changes](#breaking-changes) and the migration steps).
- The new `popBlocking()`, `awaitMessage()`, `retryOnConnectionLoss()`, `disconnect()`,
  `delayWithJitter()`, `sleepMicros()`, `processingListName()`, `recoverProcessing()` and
  `removeFromProcessing()` methods are `protected`, and exist as extension points for subclasses and tests.
- The dependency on `byjg/message-queue-client` is now `^7.0` (see [Requirements](#requirements)).

## Links

- [Documentation](https://github.com/byjg/php-redis-queue-client/tree/main/docs)
- [Report Issues](https://github.com/byjg/php-redis-queue-client/issues)

## Requirements

- PHP 8.3, 8.4, 8.5 and 8.6 are now supported: `"php": ">=8.3 <8.7"`.
  The previous `<8.6` upper bound excluded PHP 8.6, since `<8.6` is exclusive.
- Redis server 6.2 or later, and `ext-redis` 6.0 or later (for `BLMOVE`/`LMOVE`).

### ByJG dependencies

- `byjg/message-queue-client` is now `^7.0`.

While 7.0 is unreleased these resolve to `7.0.x-dev` from each component's
`7.0` branch, via `minimum-stability: dev` with `prefer-stable: true`.

## Toolchain

- PHPUnit updated to `^12.5`.
- Psalm is installed as `psalm/phar` instead of `vimeo/psalm`.

  `vimeo/psalm` lists the PHP versions it supports and no published release includes
  8.6, so as a dev dependency it made `composer install` fail on the 8.6 build job
  before any test ran. `psalm/phar` requires only `php ^8.2` and bundles its own
  dependencies, so it installs on every PHP version in the matrix and cannot conflict
  with the project's. Psalm itself still refuses to *run* on 8.6, which is why the
  Psalm job uses 8.5. `composer psalm` runs it.

- PHPUnit 13 is deliberately **not** used. It requires PHP `>=8.4.1`, breaking the
  8.3 floor.

## Continuous Integration

- The build matrix now includes PHP 8.6.
- The Psalm job runs on PHP 8.5.

## Housekeeping

- `phpunit.xml.dist` renamed to `phpunit.xml`.
