# Redis Queue Client

[![Build Status](https://github.com/byjg/php-redis-queue-client/actions/workflows/phpunit.yml/badge.svg?branch=main)](https://github.com/byjg/php-redis-queue-client/actions/workflows/phpunit.yml)
[![Opensource ByJG](https://img.shields.io/badge/opensource-byjg-success.svg)](http://opensource.byjg.com)
[![GitHub source](https://img.shields.io/badge/Github-source-informational?logo=github)](https://github.com/byjg/php-redis-queue-client/)
[![GitHub license](https://img.shields.io/github/license/byjg/php-redis-queue-client.svg)](https://opensource.byjg.com/opensource/licensing.html)
[![GitHub release](https://img.shields.io/github/release/byjg/php-redis-queue-client.svg)](https://github.com/byjg/php-redis-queue-client/releases/)

A simple and efficient abstraction layer for publishing and consuming messages from Redis queues using the [byjg/message-queue-client](https://github.com/byjg/message-queue-client) framework.

## Documentation

- [Installation](docs/installation.md)
- [Connection](docs/connection.md)
- [Publishing Messages](docs/publishing.md)
- [Consuming Messages](docs/consuming.md)
- [Error Handling](docs/error-handling.md)

## Usage

### Publish

```php
<?php
// Register the connector and associate with a scheme
ConnectorFactory::registerConnector(RedisQueueConnector::class);

// Create a connector
$connector = ConnectorFactory::create(new Uri("redis://$user:$pass@$host:$port"));

// Create a queue
$pipe = new Pipe("test");
$pipe->withDeadLetter(new Pipe("dlq_test"));

// Create a message
$message = new Message("Hello World");

// Publish the message into the queue
$connector->publish(new Envelope($pipe, $message));
```

### Consume

```php
<?php
// Register the connector and associate with a scheme
ConnectorFactory::registerConnector(RedisQueueConnector::class);

// Create a connector
$connector = ConnectorFactory::create(new Uri("redis://$user:$pass@$host:$port"));

// Create a queue
$pipe = new Pipe("test");
$pipe->withDeadLetter(new Pipe("dlq_test"));

// Connect to the queue and wait to consume the message
$connector->consume(
    $pipe,                                 // Queue name
    function (Envelope $envelope) {         // Callback function to process the message
        echo "Process the message";
        echo $envelope->getMessage()->getBody();
        return Message::ACK;
    },
    function (Envelope $envelope, $ex) {    // Callback function to process the failed message
        echo "Process the failed message";
        echo $ex->getMessage();
        return Message::REQUEUE;
    }
);
```

The consume method will wait for a message and call the callback function to process the message.
If there is no message in the queue, the method will wait until a message arrives.

If you want to exit the consume method, just return `Message::ACK | Message::EXIT` from the callback function.

Possible return values from the callback function:

* `Message::ACK` - Acknowledge the message and remove from the queue
* `Message::NACK` - Not acknowledge the message and remove from the queue. If the queue has a dead letter queue, the message will be sent to the dead letter queue.
* `Message::REQUEUE` - Requeue the message
* `Message::EXIT` - Exit the consume method

## Protocols

| Protocol | URI Example                 | Notes               |
|----------|-----------------------------|---------------------|
| Redis    | redis://user:pass@host:port | Default port: 6379  |

## Dependencies

```mermaid
flowchart TD
    byjg/redis-queue-client --> byjg/message-queue-client
```

----
[Open source ByJG](http://opensource.byjg.com)
