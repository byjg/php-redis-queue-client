# Redis Queue Client

It creates a simple abstraction layer to publish and consume messages from the Redis using the component [byjg/message-queue-client](https://github.com/byjg/message-queue-client).

For details on how to use the Message Queue Client see the [documentation](https://github.com/byjg/message-queue-client)

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

## Protocols:

| Protocol | URI Example                                         | Notes                                                                                  |
|----------|-----------------------------------------------------|----------------------------------------------------------------------------------------|
| Redis    | redis://user:pass@host:port                         | Default port: 6379.                                                                    |

----
[Open source ByJG](http://opensource.byjg.com)
