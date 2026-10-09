# Changelog - Version 7.0

> **Status: in development.** This document tracks changes landing on the `7.0` branch.
> Nothing here is released yet, and the contents may still change.

## Overview

Version 7.0 fixes a consumer that could neither survive nor recover from the broker going away. A
lost Redis connection either pinned a CPU core at roughly 1.6 million iterations per second or
killed the consumer outright, and in neither case did it ever reconnect.

Part of the coordinated move of the byjg PHP components to 7.0.

## Breaking Changes

| Area | Before (6.x) | After (7.0) | Description |
|------|--------------|-------------|-------------|
| **Lost connections** | `RedisException` escaped `consume()` | caught, and retried indefinitely | A consumer no longer exits when Redis goes away. If you relied on that exception to end the process and have a supervisor restart it, the consumer now rides the outage out instead. |

## Bug Fixes

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

## Notes

- `ConnectorInterface` is unchanged; nothing written against 6.x needs modification.
- The new `popBlocking()`, `awaitMessage()`, `disconnect()`, `delayWithJitter()` and `sleepMicros()`
  methods are `protected`, and exist as extension points for subclasses and tests.
- The dependency on `byjg/message-queue-client` remains `^6.0` and moves to `^7.0` once that package
  is released.

## Links

- [Documentation](https://github.com/byjg/php-redis-queue-client/tree/main/docs)
- [Report Issues](https://github.com/byjg/php-redis-queue-client/issues)

## Requirements

- PHP 8.3, 8.4, 8.5 and 8.6 are now supported: `"php": ">=8.3 <8.7"`.
  The previous `<8.6` upper bound excluded PHP 8.6, since `<8.6` is exclusive.

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
