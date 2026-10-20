# Changelog - Version 6.0

## Overview

Version 6.0 is a major release that includes PHP 8.3+ compatibility, new features, documentation improvements, and several breaking changes. This release modernizes the codebase while adding support for priority queues and enhanced RabbitMQ features.

---

## New Features

### Priority Queues Support
- Added full support for RabbitMQ priority queues
- Messages can now be prioritized from 0-255 (0-10 recommended for performance)
- Queue must be declared with `x-max-priority` property
- Higher priority messages are consumed before lower priority ones
- See [Priority Queues documentation](docs/priority-queues.md) for details

### Comprehensive Documentation
- Added detailed Docusaurus documentation site
- New documentation for connection parameters: [Connection Parameters](docs/connection-parameters.md)
- New documentation for SSL/TLS configuration: [SSL Configuration](docs/ssl-configuration.md)
- New documentation for robust connections: [Robust Connections](docs/robust-connections.md)
- Enhanced README with better examples and usage patterns

### Improved Null Handling
- Better null safety in RabbitMQConnector
- Improved handling of nullable channel and consumer tag properties
- More robust connection cleanup on errors and exit conditions

### Enhanced Testing
- Added Docker Compose configuration for local development
- Updated PHPUnit workflow with container options
- Added RabbitMQ run configuration for easier testing

---

## Bug Fixes

- Fixed null pointer issues in channel cleanup during consume operations
- Improved error handling when closing connections and channels
- Better handling of username/password defaults (fallback to 'guest')
- More robust port configuration using null coalescing operator

---

## Breaking Changes

| Area | Before (5.x) | After (6.0) | Description |
|------|-------------|------------|-------------|
| **PHP Version** | `PHP >=8.1 <8.4` | `PHP >=8.3 <8.6` | Minimum PHP version increased to 8.3, added support for PHP 8.4 and 8.5 |
| **Message Queue Client** | ^5.0 | ^6.0 | Updated dependency to message-queue-client 6.0 |
| **PHPUnit** | 5.7.*\|7.4.*\|^9.5 | ^10.5\|^11.5 | Upgraded to PHPUnit 10.5+ or 11.5+ |
| **Psalm** | ^5.9 | ^5.9\|^6.13 | Added support for Psalm 6.13 |
| **Constant Visibility** | `const ROUTING_KEY` | `public const ROUTING_KEY` | Changed class constants to explicitly public |
| **Override Attributes** | Not present | `#[\Override]` added | Added PHP 8.3 Override attributes to interface methods |
| **Psalm Level** | Level 8 | Level 7 | Psalm error level changed from 8 to 7 (less strict) |
| **Docker Compose** | docker-compose-for-test.yaml | docker-compose.yaml | Renamed docker compose file to standard name |

### Internal Method Changes
| Method | Before (5.x) | After (6.0) | Description |
|--------|-------------|------------|-------------|
| `createQueue()` | `createQueue($connection, $pipe, $withExchange = true)` | `createQueue($connection, $pipe)` | Removed `$withExchange` parameter - exchanges are always declared |
| `lazyConnect()` | `lazyConnect($pipe, $withExchange = true)` | `lazyConnect($pipe)` | Removed `$withExchange` parameter - exchanges are always declared |

---

## Upgrade Path from 5.x to 6.0

### Step 1: Update PHP Version
Ensure your environment is running **PHP 8.3 or higher**:

```bash
php -v
```

If you're using PHP 8.1 or 8.2, you must upgrade to PHP 8.3, 8.4, or 8.5.

### Step 2: Update Composer Dependencies

Update your `composer.json`:

```json
{
  "require": {
    "php": ">=8.3",
    "byjg/rabbitmq-client": "^6.0"
  }
}
```

Then run:

```bash
composer update byjg/rabbitmq-client byjg/message-queue-client
```

### Step 3: Update Test Dependencies

If you're using PHPUnit, update to version 10.5 or 11.5:

```bash
composer require --dev phpunit/phpunit:^11.5
```

If you're using Psalm, you can optionally upgrade to version 6:

```bash
composer require --dev vimeo/psalm:^6.13
```

### Step 4: Code Changes (if extending RabbitMQConnector)

If you've extended the `RabbitMQConnector` class and overridden internal methods, you'll need to update method signatures:

**Before (5.x):**
```php
protected function createQueue(AbstractConnection $connection, Pipe $pipe, bool $withExchange = true): AMQPChannel
{
    // your implementation
}

protected function lazyConnect(Pipe $pipe, $withExchange = true): array
{
    // your implementation
}
```

**After (6.0):**
```php
protected function createQueue(AbstractConnection $connection, Pipe $pipe): AMQPChannel
{
    // your implementation - exchange is always declared
}

protected function lazyConnect(Pipe $pipe): array
{
    // your implementation - exchange is always declared
}
```

**Important:** The `$withExchange` parameter has been removed because exchanges are now always declared. If you were calling these methods with `$withExchange = false`, you'll need to refactor your code.

### Step 5: Test Your Application

Run your test suite to ensure everything works correctly:

```bash
vendor/bin/phpunit
```

Run Psalm to check for type issues:

```bash
vendor/bin/psalm
```

### Step 6: Optional - Use New Features

Consider taking advantage of new features:

#### Priority Queues
```php
// Declare queue with max priority
$pipe = new Pipe("priority_queue");
$pipe->withProperty('x-max-priority', 10);

// Send high priority message
$message = new Message("Urgent task");
$message->withProperty('priority', 10);
$connector->publish(new Envelope($pipe, $message));
```

#### Enhanced Documentation
Review the new documentation to learn about:
- Connection parameter optimization
- SSL/TLS configuration best practices
- Robust connection patterns for production environments

---

## Additional Notes

### Docker Compose
The docker compose file has been renamed from `docker-compose-for-test.yaml` to `docker-compose.yaml`. If you reference this file in scripts, update your references:

**Before:**
```bash
docker-compose -f docker-compose-for-test.yaml up -d
```

**After:**
```bash
docker-compose up -d
```

### Psalm Configuration
If you have strict Psalm configuration, note that the library now uses level 7 instead of level 8. This should not affect your application but may allow slightly looser type checking in the library code.

---

## Migration Checklist

- [ ] Verify PHP version is 8.3 or higher
- [ ] Update composer.json with new version constraints
- [ ] Run `composer update`
- [ ] Update PHPUnit to version 10.5+ or 11.5+
- [ ] If extending RabbitMQConnector, update method signatures
- [ ] Update docker-compose file references if applicable
- [ ] Run test suite to verify compatibility
- [ ] Review new documentation for optimization opportunities
- [ ] Consider implementing priority queues if needed

---

## Support

For issues or questions about the upgrade:
- GitHub Issues: https://github.com/byjg/php-rabbitmq-client/issues
- Documentation: https://github.com/byjg/php-rabbitmq-client/tree/main/docs

---

**Release Date:** 2025
**Maintainer:** ByJG
