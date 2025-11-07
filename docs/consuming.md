---
sidebar_position: 4
---

# Consuming Messages

The consume method allows you to process messages from a Redis queue. It will continuously wait for messages and process them using callback functions.

## Basic Consumption

```php
<?php
use ByJG\MessageQueueClient\ConnectorFactory;
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

- **`Message::ACK`** - Acknowledge and remove the message from the queue
- **`Message::NACK`** - Not acknowledge the message. If a dead letter queue is configured, the message will be sent there
- **`Message::REQUEUE`** - Put the message back into the queue for reprocessing
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

## Blocking Behavior

The `consume()` method uses Redis `BRPOP` (blocking right pop):
- It will **block and wait** indefinitely until a message becomes available
- The timeout is set to 0, meaning it waits forever for the next message
- This is efficient as it doesn't poll the queue constantly
- The loop continues until you return `Message::EXIT` or the process is terminated

## Identification Parameter

The consume method accepts an optional `$identification` parameter (currently not used by Redis connector):

```php
$connector->consume($pipe, $onReceive, $onError, "worker-1");
```

This parameter is part of the interface for compatibility with other queue connectors.
