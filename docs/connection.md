---
sidebar_position: 2
---

# Connection

## Registering the Connector

Before using the Redis Queue Client, you need to register the connector with the ConnectorFactory:

```php
<?php
use ByJG\MessageQueueClient\ConnectorFactory;
use ByJG\MessageQueueClient\RedisQueue\RedisQueueConnector;

ConnectorFactory::registerConnector(RedisQueueConnector::class);
```

## Creating a Connection

Create a connector instance using a Redis URI:

```php
<?php
use ByJG\MessageQueueClient\ConnectorFactory;
use ByJG\Util\Uri;

$connector = ConnectorFactory::create(new Uri("redis://$user:$pass@$host:$port"));
```

## Connection URI Format

The Redis connection URI follows this format:

```
redis://[user[:password]@]host[:port]
```

### Parameters

- **user** (optional): Redis username (ACL authentication)
- **password** (optional): Redis password
- **host** (required): Redis server hostname or IP address
- **port** (optional): Redis server port (default: 6379)

### Examples

**Simple connection without authentication:**
```php
$connector = ConnectorFactory::create(new Uri("redis://localhost:6379"));
```

**Connection with password only:**
```php
$connector = ConnectorFactory::create(new Uri("redis://:mypassword@localhost:6379"));
```

**Connection with username and password (ACL):**
```php
$connector = ConnectorFactory::create(new Uri("redis://myuser:mypassword@localhost:6379"));
```

**Using default port (6379):**
```php
$connector = ConnectorFactory::create(new Uri("redis://localhost"));
```

## Connection Features

The connector automatically:
- Uses lazy loading for the Redis connection
- Configures serialization to `SERIALIZER_NONE` for plain text message handling
- Authenticates using username/password when provided
- Validates the connection by checking the Redis version
