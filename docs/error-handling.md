---
sidebar_position: 5
---

# Error Handling

The Redis Queue Client provides robust error handling mechanisms to manage failed messages and ensure message reliability.

## Dead Letter Queues

A Dead Letter Queue (DLQ) is a separate queue that stores messages that cannot be processed successfully.

### Configuring a DLQ

Attach a dead letter queue to your main pipe:

```php
use ByJG\MessageQueueClient\Connector\Pipe;

$mainPipe = new Pipe("orders");
$mainPipe->withDeadLetter(new Pipe("dlq_orders"));
```

### When Messages Go to DLQ

Messages are sent to the dead letter queue when the consumer returns `Message::NACK`:

```php
$connector->consume(
    $mainPipe,
    function (Envelope $envelope) {
        try {
            processOrder($envelope->getMessage()->getBody());
            return Message::ACK;
        } catch (InvalidOrderException $e) {
            // Permanently failed, send to DLQ
            return Message::NACK;
        }
    },
    function (Envelope $envelope, $ex) {
        // Critical error during processing
        error_log("Critical error: " . $ex->getMessage());
        return Message::NACK; // Send to DLQ
    }
);
```

## Error Recovery Strategies

### Strategy 1: Requeue for Retry

Use `Message::REQUEUE` for temporary failures:

```php
$connector->consume(
    $pipe,
    function (Envelope $envelope) {
        return Message::ACK;
    },
    function (Envelope $envelope, $ex) {
        if ($ex instanceof TemporaryException) {
            // Temporary issue, try again
            return Message::REQUEUE;
        }

        // Permanent failure, send to DLQ
        return Message::NACK;
    }
);
```

:::caution
Be careful with `REQUEUE` - it can cause infinite loops if the failure condition persists. Consider implementing retry limits in your application logic.
:::

### Strategy 2: Dead Letter Queue

Use `Message::NACK` for permanent failures:

```php
$connector->consume(
    $pipe,
    function (Envelope $envelope) {
        $data = json_decode($envelope->getMessage()->getBody(), true);

        if (!validateData($data)) {
            // Invalid data, don't requeue
            return Message::NACK;
        }

        processData($data);
        return Message::ACK;
    },
    function (Envelope $envelope, $ex) {
        error_log("Processing failed: " . $ex->getMessage());
        return Message::NACK;
    }
);
```

### Strategy 3: Acknowledge and Log

Sometimes you want to acknowledge a message even if processing failed:

```php
$connector->consume(
    $pipe,
    function (Envelope $envelope) {
        return Message::ACK;
    },
    function (Envelope $envelope, $ex) {
        // Log the error
        error_log("Error: " . $ex->getMessage());

        // Store in database for manual review
        logFailedMessage($envelope->getMessage()->getBody(), $ex);

        // Acknowledge anyway to prevent reprocessing
        return Message::ACK;
    }
);
```

## Processing DLQ Messages

You can consume messages from the dead letter queue for manual inspection or reprocessing:

```php
$dlqPipe = new Pipe("dlq_orders");

$connector->consume(
    $dlqPipe,
    function (Envelope $envelope) {
        $body = $envelope->getMessage()->getBody();

        // Log or store for manual review
        logFailedMessage($body);

        // Try to fix and republish to main queue if possible
        if (canBeFixed($body)) {
            $fixedMessage = fixMessage($body);
            $mainPipe = new Pipe("orders");
            $connector->publish(new Envelope($mainPipe, new Message($fixedMessage)));
        }

        return Message::ACK;
    },
    function (Envelope $envelope, $ex) {
        // Even DLQ processing can fail
        error_log("DLQ processing error: " . $ex->getMessage());
        return Message::ACK; // Don't requeue DLQ messages
    }
);
```

## Exception Types

The error callback receives any `Exception` or `Error` thrown during message processing:

```php
function (Envelope $envelope, Exception|Error $ex) {
    // Handle different error types
    if ($ex instanceof \Redis\Exception) {
        // Redis connection issue
        return Message::REQUEUE;
    }

    if ($ex instanceof \JsonException) {
        // Invalid JSON, can't be fixed
        return Message::NACK;
    }

    // Default: send to DLQ
    return Message::NACK;
}
```

## Best Practices

### 1. Always Configure a DLQ

```php
// Good: Has DLQ configured
$pipe = new Pipe("important-queue");
$pipe->withDeadLetter(new Pipe("dlq_important-queue"));
```

### 2. Implement Comprehensive Logging

```php
$connector->consume(
    $pipe,
    function (Envelope $envelope) {
        $startTime = microtime(true);

        try {
            $result = processMessage($envelope->getMessage()->getBody());

            $duration = microtime(true) - $startTime;
            logger()->info("Message processed", [
                'duration' => $duration,
                'result' => $result
            ]);

            return Message::ACK;
        } catch (Exception $ex) {
            logger()->error("Processing failed", [
                'error' => $ex->getMessage(),
                'message' => $envelope->getMessage()->getBody()
            ]);
            throw $ex; // Will be caught by error callback
        }
    },
    function (Envelope $envelope, $ex) {
        logger()->critical("Message error", [
            'exception' => get_class($ex),
            'message' => $ex->getMessage(),
            'trace' => $ex->getTraceAsString()
        ]);

        return Message::NACK;
    }
);
```

### 3. Distinguish Between Temporary and Permanent Failures

```php
function (Envelope $envelope, $ex) {
    // Temporary failures - retry
    if ($ex instanceof NetworkException || $ex instanceof TimeoutException) {
        return Message::REQUEUE;
    }

    // Permanent failures - DLQ
    if ($ex instanceof ValidationException || $ex instanceof DataException) {
        return Message::NACK;
    }

    // Unknown - send to DLQ for investigation
    return Message::NACK;
}
```

### 4. Monitor Your DLQ

Set up monitoring and alerts for your dead letter queues:

```php
// Periodically check DLQ depth
$dlqSize = $connector->getDriver()->llen("dlq_orders");

if ($dlqSize > 100) {
    sendAlert("DLQ size exceeded threshold: $dlqSize messages");
}
```

## Redis Connection Errors

Connection errors to Redis will throw `RedisException`:

```php
use RedisException;

try {
    $connector->consume($pipe, $onReceive, $onError);
} catch (RedisException $ex) {
    // Redis connection lost
    error_log("Redis connection error: " . $ex->getMessage());

    // Implement reconnection logic
    sleep(5);
    // retry...
}
```
