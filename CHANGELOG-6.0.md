# Changelog - Version 6.0

## Overview

Version 6.0 is a major release that brings improved code quality, better Redis authentication handling, enhanced documentation, and updated dependencies. This release drops support for PHP 8.1 and 8.2 in favor of PHP 8.3+.

## New Features

### Comprehensive Documentation
- Added complete Docusaurus-compatible documentation structure in `/docs` folder
  - Installation guide (`docs/installation.md`)
  - Connection configuration (`docs/connection.md`)
  - Publishing messages guide (`docs/publishing.md`)
  - Consuming messages guide (`docs/consuming.md`)
  - Error handling documentation (`docs/error-handling.md`)
- Updated README.md with documentation section linking to all guides

### Improved Code Quality
- Added PHP 8.1+ `#[\Override]` attributes to interface implementations for better type safety
- Enhanced Psalm static analysis (upgraded to error level 3)
- Improved PHPUnit configuration with testdox format and additional flags
- Added composer scripts for easier testing and static analysis:
  - `composer test` - Run PHPUnit tests
  - `composer psalm` - Run Psalm static analysis

### Enhanced CI/CD
- Consolidated GitHub Actions workflow with dedicated static analysis job
- Added SARIF upload for better security analysis integration
- Upgraded to `actions/checkout@v5`
- Improved Docker container configuration with proper privileges

## Bug Fixes

### Redis Connection Improvements
- **Simplified authentication logic**: Refactored username and password handling to be more robust and type-safe
- **Better null handling**: Improved handling of optional username/password parameters using strict null checks
- **Empty message handling**: Fixed potential issues with `brpop` returning `false` or `null` by adding explicit checks to continue consumption loop
- **Dead letter queue safety**: Enhanced null safety when checking for dead letter queue configuration

## Breaking Changes

| Component | Before (5.x) | After (6.0) | Description |
|-----------|-------------|-------------|-------------|
| PHP Version | `>=8.1 <8.4` | `>=8.3 <8.6` | Minimum PHP version raised from 8.1 to 8.3. Support added for PHP 8.4 and 8.5 |
| PHPUnit | `^9.6` | `^10.5\|^11.5` | Updated to PHPUnit 10 or 11 for PHP 8.3+ compatibility |
| Psalm | `^5.9` | `^5.9\|^6.13` | Added support for Psalm 6.x |
| message-queue-client | `^5.0` | `^6.0` | Updated to version 6.0 of the base message queue client library |
| Redis Authentication | Loosely typed checks (`empty()`) | Strict null checks (`!== null && !== ''`) | Authentication logic now uses strict type checking instead of loose empty checks |
| File Naming | `docker-compose-for-test.yaml` | `docker-compose.yml` | Renamed Docker Compose file to standard naming |

## Path to Upgrade from 5.x to 6.x

### 1. Update PHP Version
Ensure your environment is running **PHP 8.3 or higher**:

```bash
php -v
```

If you're on PHP 8.1 or 8.2, you'll need to upgrade your PHP installation before upgrading to version 6.0.

### 2. Update Composer Dependencies

Update your `composer.json` to require version 6.0:

```json
{
    "require": {
        "byjg/redis-queue-client": "^6.0"
    }
}
```

Then run:

```bash
composer update byjg/redis-queue-client
```

This will also automatically update `byjg/message-queue-client` to version 6.0.

### 3. Update Development Dependencies (Optional)

If you're using PHPUnit and Psalm in your development environment, update them to compatible versions:

```bash
composer require --dev phpunit/phpunit:^10.5|^11.5
composer require --dev vimeo/psalm:^5.9|^6.13
```

### 4. Test Your Application

The changes in 6.0 are primarily internal improvements. Your existing code should work without modifications, but you should thoroughly test:

1. **Redis Connection**: Test connections with and without authentication
2. **Message Publishing**: Verify message publishing works as expected
3. **Message Consumption**: Test message consumption and error handling
4. **Dead Letter Queues**: If using DLQ, verify proper message routing

### 5. Update Docker Compose (If Applicable)

If you're using the Docker Compose setup for testing, rename your file:

```bash
mv docker-compose-for-test.yaml docker-compose.yml
```

### 6. Review Documentation

Review the new documentation in the `/docs` folder for updated best practices and examples:
- Connection configuration patterns
- Publishing and consuming patterns
- Error handling strategies

### 7. Known Compatibility Notes

- **No code changes required**: The API remains backward compatible
- **Authentication behavior**: While authentication logic was refactored, it maintains the same external behavior with improved type safety
- **Message consumption**: The fix for `brpop` null handling is transparent to consumers

## Additional Notes

- This release focuses on code quality, documentation, and modernization
- No new features that change the public API
- All changes maintain backward compatibility at the API level
- The main impact is the PHP version requirement increase

For more details on any specific change, please refer to the commit history or the updated documentation.
