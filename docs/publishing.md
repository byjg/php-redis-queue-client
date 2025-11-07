---
sidebar_position: 3
---

# Publishing Messages

Publishing messages to a Redis queue involves creating a pipe (queue), a message, and an envelope to wrap them together.

## Basic Publishing

```php
<?php
use ByJG\MessageQueueClient\ConnectorFactory;
use ByJG\MessageQueueClient\Connector\Pipe;
use ByJG\MessageQueueClient\Message;
use ByJG\MessageQueueClient\Envelope;
use ByJG\MessageQueueClient\RedisQueue\RedisQueueConnector;
use ByJG\Util\Uri;

// Register and create connector
ConnectorFactory::registerConnector(RedisQueueConnector::class);
$connector = ConnectorFactory::create(new Uri("redis://localhost:6379"));

// Create a queue (pipe)
$pipe = new Pipe("test");

// Create a message
$message = new Message("Hello World");

// Publish the message
$connector->publish(new Envelope($pipe, $message));
```

## Creating a Pipe

A pipe represents a queue in Redis. Create it with a queue name:

```php
$pipe = new Pipe("my-queue-name");
```

### Pipe with Dead Letter Queue

You can configure a dead letter queue (DLQ) to handle failed messages:

```php
$pipe = new Pipe("test");
$pipe->withDeadLetter(new Pipe("dlq_test"));
```

When a message is not acknowledged (NACK), it will be sent to the dead letter queue.

## Creating a Message

Messages are created with a body (string content):

```php
// Simple text message
$message = new Message("Hello World");

// JSON message
$message = new Message(json_encode(['key' => 'value']));

// Any string content
$message = new Message("Any text content here");
```

### Message Properties

Messages support additional properties:

```php
$message = new Message("Hello");
$properties = $message->getProperties();
// Default content_type is 'text/plain'
```

## Publishing Messages

Wrap the pipe and message in an Envelope and publish:

```php
$envelope = new Envelope($pipe, $message);
$connector->publish($envelope);
```

## How It Works

When you publish a message:
1. The connector uses Redis `LPUSH` to add the message to the left side of the list
2. The message body is stored as a plain string (no serialization)
3. The content type defaults to `text/plain` if not specified
4. Consumers will retrieve messages from the right side using `BRPOP`
