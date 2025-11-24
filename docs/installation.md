---
sidebar_position: 1
---

# Installation

## Requirements

- PHP 8.3 or higher (up to 8.5)
- Redis PHP extension (`ext-redis`)
- cURL PHP extension (`ext-curl`)

## Installing via Composer

Install the package using Composer:

```bash
composer require byjg/redis-queue-client
```

This will automatically install the required dependencies, including:
- `byjg/message-queue-client` - The base message queue client framework

## Verifying Installation

After installation, verify that the Redis extension is enabled:

```bash
php -m | grep redis
```

You should see `redis` in the output.
