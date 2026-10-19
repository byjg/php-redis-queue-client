---
sidebar_position: 4
---

# Consuming Messages

The consume method allows you to process messages from a Redis queue. It will continuously wait for messages and process them using callback functions.

## Basic Consumption

```php
<?php
use ByJG\MessageQueueClient\Connector\ConnectorFactory;
use ByJG\MessageQueueClient\Connector\Pipe;
use ByJG\MessageQueueClient\Envelope;
use ByJG\MessageQueueClient\Message;
use ByJG\MessageQueueClient\RedisQueue\RedisQueueConnector;
use ByJG\Util\Uri;

// Register and create connector
ConnectorFactory::registerConnector(RedisQueueConnector::class);
$connector = ConnectorFactory::create(new Uri("redis://localhost:6379"));

// Create a queue
$pipe = new Pipe("test");
$pipe->withDeadLetter(new Pipe("dlq_test"));

// Start consuming
$connector->consume(
    $pipe,
    function (Envelope $envelope) {
        echo "Processing message: " . $envelope->getMessage()->getBody();
        return Message::ACK;
    },
    function (Envelope $envelope, $ex) {
        echo "Error: " . $ex->getMessage();
        return Message::REQUEUE;
    }
);
```

## Callback Functions

The consume method requires two callback functions:

### Success Callback

Called when a message is successfully retrieved:

```php
function (Envelope $envelope) {
    // Access message body
    $body = $envelope->getMessage()->getBody();

    // Process the message
    // ... your logic here ...

    // Return action to take
    return Message::ACK;
}
```

### Error Callback

Called when an exception or error occurs during message processing:

```php
function (Envelope $envelope, Exception|Error $ex) {
    // Log the error
    error_log($ex->getMessage());

    // Access the message that caused the error
    $body = $envelope->getMessage()->getBody();

    // Return action to take
    return Message::REQUEUE;
}
```

## Return Values

Control message flow by returning one or more of these constants:

### Single Actions

- **`Message::ACK`** - Acknowledge the message and remove it from the processing list
- **`Message::NACK`** - Not acknowledge the message. If a dead letter queue is configured, the message will be sent there. It is then removed from the processing list
- **`Message::REQUEUE`** - Publish the message again (at the back of the queue) for reprocessing, then remove it from the processing list
- **`Message::EXIT`** - Stop consuming and exit the consume loop

### Combined Actions

You can combine actions using the bitwise OR operator (`|`):

```php
// Acknowledge the message AND exit the consumer
return Message::ACK | Message::EXIT;
```

```php
// Requeue the message AND exit the consumer
return Message::REQUEUE | Message::EXIT;
```

## Examples

### Simple Message Processing

```php
$connector->consume(
    $pipe,
    function (Envelope $envelope) {
        $data = json_decode($envelope->getMessage()->getBody(), true);

        // Process data
        processData($data);

        return Message::ACK;
    },
    function (Envelope $envelope, $ex) {
        error_log("Failed to process: " . $ex->getMessage());
        return Message::NACK; // Send to dead letter queue
    }
);
```

### Processing with Exit Condition

```php
$processedCount = 0;

$connector->consume(
    $pipe,
    function (Envelope $envelope) use (&$processedCount) {
        $body = $envelope->getMessage()->getBody();

        // Process message
        processMessage($body);
        $processedCount++;

        // Exit after processing 100 messages
        if ($processedCount >= 100) {
            return Message::ACK | Message::EXIT;
        }

        return Message::ACK;
    },
    function (Envelope $envelope, $ex) {
        error_log($ex->getMessage());
        return Message::REQUEUE;
    }
);
```

### Conditional Requeuing

```php
$connector->consume(
    $pipe,
    function (Envelope $envelope) {
        $data = json_decode($envelope->getMessage()->getBody(), true);

        if (shouldProcessNow($data)) {
            processData($data);
            return Message::ACK;
        } else {
            // Not ready to process, put it back
            return Message::REQUEUE;
        }
    },
    function (Envelope $envelope, $ex) {
        // On error, send to dead letter queue
        return Message::NACK;
    }
);
```

## Reliable Delivery

`consume()` uses the Redis reliable-queue pattern, so a consumer that dies (killed, crash, out of
memory) while handling a message does not lose it:

1. The message is taken with `BLMOVE`, which atomically moves it from the queue into a
   **processing list**. It is never only in the consumer's memory.
2. The callback runs and its outcome is applied: nothing for `ACK`, publish to the dead letter queue
   for `NACK`, publish back to the queue for `REQUEUE`.
3. Only then is the message removed from the processing list (`LREM`).
4. When `consume()` starts, it moves everything left in its processing list back to the queue (one
   atomic `LMOVE` per message). Those messages are delivered next, in their original order.

The processing list is named after the queue:

| `consume()` called with      | Processing list               |
|------------------------------|-------------------------------|
| no `$identification`         | `<queue>:processing`          |
| `$identification = "worker-1"` | `<queue>:processing:worker-1` |

:::caution At-least-once delivery
A consumer that dies after the callback did its work but before step 3 leaves the message in the
processing list, and it is delivered again once recovered. A message can therefore be processed more
than once; make your handlers idempotent.
:::

### Running several consumers on the same queue

Recovery at start empties the **whole** processing list of that consumer. If several consumers of one
queue share the default `<queue>:processing`, a consumer that starts takes the in-flight messages of
the others still running and delivers them a second time.

So:

- **One consumer per queue**: nothing to do; the default processing list is fine.
- **Several consumers per queue**: give each one a distinct, **stable** identification (for example
  `worker-1`, `worker-2`, the same after every restart). Each has its own processing list, and a
  consumer restarted under the same identification gets back what its previous run left there.

A processing list whose consumer never comes back under the same identification (for example, when
identifications are random or include a PID, or when you scale down) is not recovered automatically.
Find such lists with `SCAN 0 MATCH <queue>:processing:*` and push their content back with
`LMOVE <list> <queue> LEFT RIGHT` (or start a consumer with that identification).

## Blocking Behavior

The `consume()` method uses Redis `BLMOVE` (blocking move from the right of the queue to the
processing list; requires Redis 6.2 or later):
- It will **block and wait** indefinitely until a message becomes available
- The timeout is set to 0, meaning it waits forever for the next message
- This is efficient as it doesn't poll the queue constantly
- The loop continues until you return `Message::EXIT` or the process is terminated

If the connection to Redis is lost while waiting, `consume()` does not throw. It drops the connection and
reconnects, waiting between attempts with a backoff that starts at 200ms and doubles up to 30 seconds
(randomised between half the delay and all of it). The backoff resets once a message is received.

The publish done for `Message::NACK` (to the dead letter queue) and `Message::REQUEUE`, and the removal
from the processing list, are retried the same way if the connection is lost, so the message that was
just received is not dropped.

## Identification Parameter

The consume method accepts an optional `$identification` parameter. The Redis connector uses it to name
the consumer's processing list (`<queue>:processing:<identification>`), see
[Running several consumers on the same queue](#running-several-consumers-on-the-same-queue):

```php
$connector->consume($pipe, $onReceive, $onError, "worker-1");
```
