# Changelog - Version 7.0

> **Status: in development.** This document tracks changes landing on the `7.0` branch.
> Nothing here is released yet, and the contents may still change.

## Overview

Version 7.0 fixes a defect that killed healthy consumers on quiet queues, and stops a fleet of
consumers reconnecting in lockstep after a broker outage.

Part of the coordinated move of the byjg PHP components to 7.0.

## Breaking Changes

| Area | Before (6.x) | After (7.0) | Description |
|------|--------------|-------------|-------------|
| **Idle timeouts** | counted towards `max_attempts` | ignored | A `timeout` elapsing with no message no longer consumes a reconnection attempt. If you were relying on a short `timeout` plus a low `max_attempts` as an informal "stop after N idle periods" mechanism, that no longer exits — the consumer keeps waiting, which is what the documentation always described. |
| **Reconnect delays** | fixed 1, 2, 4, 8 … seconds | randomised between half and all of the computed delay | Reconnection timing is no longer reproducible. Anything asserting an exact reconnect schedule will need to assert a range. |
| **AMQPS peer verification** | off unless `verify_peer=true` / `verify_peer_name=true` | on unless `verify_peer=false` / `verify_peer_name=false` | The server certificate and its name are verified by default. A server with a self-signed certificate needs its CA in `capath`, or the parameters set to `false` (development only). |
| **`Message::REQUEUE` alone** | acked the message (the requeue only happened with `NACK \| REQUEUE`) | nacks it with requeue | The message goes back to the queue, as documented. A handler that returned `REQUEUE` and relied on the message being consumed must return `ACK`. |

## Bug Fixes

### A quiet queue killed the consumer

`$channel->wait()` throws `AMQPTimeoutException` when no message arrives within `timeout`. On a
low-traffic queue that is the ordinary case, not a fault — but it was caught by the same handler as
genuine connection errors, so every idle period incremented the reconnection counter:

```php
} catch (AMQPTimeoutException $ex) {
    $delay = $this->getBackoffDelay($attempt++);
    if ($attempt > $maxAttempts) {
        throw new Exception("Failed to recover connection after {$maxAttempts} attempts", 0, $ex);
    }
```

The counter only reset when a consumption cycle ended *without* throwing, which an idle consumer
never manages. So after `max_attempts` quiet periods — under two hours at the default 600 second
timeout — the consumer died reporting that it could not recover a connection it had never lost.

It also tore down and rebuilt a perfectly healthy connection on every timeout. That part is unchanged:
the connection is still closed and reopened after each idle timeout, but without a backoff delay and
without consuming an attempt.

The documentation already described the intended behaviour under `timeout`:

> - If no messages arrived, the consumer continues waiting

The code now does what the docs always claimed.

### Reconnections were synchronised across the fleet

`getBackoffDelay()` was fully deterministic, so every consumer that lost the same broker retried on
an identical schedule — 1s, 2s, 4s, 8s in lockstep — and the reconnect storm landed together on a
server that had only just recovered.

The delay is now jittered: half of the computed value is guaranteed, the remainder is random. The
guaranteed half is deliberate — a fully random delay can draw near zero, which would turn the
backoff into the tight retry loop it exists to prevent.

### AMQPS: client certificate and CA file

`local_cert` was passed as the CA certificate and no client certificate was ever sent, so mutual TLS
could not work; it is now the client certificate. `capath` was always passed as a CA directory, so the
documented single CA file (`capath=/path/to/ca-cert.pem`) did not work; a file is now the CA
certificate and a directory the CA path.

### `getBackoffDelay()` relied on an implicit float coercion

`pow()` returns a float, so the method returned `1.0` from an `int` signature and depended on PHP
coercing it. Adding `declare(strict_types=1)` to the file at any point would have turned that into a
`TypeError`. It now uses a bit shift, guarded so a large attempt count cannot shift past the integer
width and wrap to something nonsensical.

## Testing

`tests/BackoffTest.php` covers the reconnection timing directly — that the delay still doubles under
jitter, that it stays capped at 30 seconds for any attempt count up to `PHP_INT_MAX`, that a jittered
delay is never zero, and that it genuinely varies.

The idle-queue fix is verified by running a consumer in a forked process and asserting it is **still
alive** after seven seconds, because `consume()` deliberately never returns on an empty queue. With
`timeout=1&max_attempts=1` the 6.x code gave up after roughly four. The test was confirmed to fail
against the previous implementation before being accepted.

## Migration Path from 6.x to 7.0

### Step 1: Update the dependency

```bash
composer require byjg/rabbitmq-client:^7.0
composer update
```

### Step 2: Check for consumers relying on idle timeouts to exit

If any consumer used a short `timeout` with a low `max_attempts` to stop itself after a period of
inactivity, it will now keep waiting. Use `single_run=true`, or have the handler return
`Message::EXIT` on whatever condition should actually end the run.

### Step 3: Relax any assertions on reconnect timing

Reconnection delays are now a range rather than a fixed sequence. See the table under
[max_attempts](docs/connection-parameters.md#max_attempts).

### Step 4: Review AMQPS connections and `REQUEUE` handlers

Peer verification is now on by default: make sure `capath` holds the CA of the server, or set
`verify_peer=false&verify_peer_name=false` for a development server. A handler returning
`Message::REQUEUE` now really requeues the message.

## Notes

- `ConnectorInterface` is unchanged; connectors written against 6.x need no modification.
- The dependency on `byjg/message-queue-client` is now `^7.0` (see [Requirements](#requirements)).

## Links

- [Documentation](https://github.com/byjg/php-rabbitmq-client/tree/main/docs)
- [Report Issues](https://github.com/byjg/php-rabbitmq-client/issues)

## Requirements

- PHP 8.3, 8.4, 8.5 and 8.6 are now supported: `"php": ">=8.3 <8.7"`.
  The previous `<8.6` upper bound excluded PHP 8.6, since `<8.6` is exclusive.

### ByJG dependencies

- `byjg/message-queue-client` is now `^7.0`.

While 7.0 is unreleased these resolve to `7.0.x-dev` from each component's
`7.0` branch, via `minimum-stability: dev` with `prefer-stable: true`.

## Toolchain

- PHPUnit updated to `^12.5`.
- Psalm is installed as `psalm/phar` instead of `vimeo/psalm`.

  `vimeo/psalm` lists the PHP versions it supports and no published release includes
  8.6, so as a dev dependency it made `composer install` fail on the 8.6 build job
  before any test ran. `psalm/phar` requires only `php ^8.2` and bundles its own
  dependencies, so it installs on every PHP version in the matrix and cannot conflict
  with the project's. Psalm itself still refuses to *run* on 8.6, which is why the
  Psalm job uses 8.5. `composer psalm` runs it.

- PHPUnit 13 is deliberately **not** used. It requires PHP `>=8.4.1`, breaking the
  8.3 floor.

## Continuous Integration

- The build matrix now includes PHP 8.6.
- The Psalm job runs on PHP 8.5.

## Housekeeping

- `phpunit.xml.dist` renamed to `phpunit.xml`.
- `docker-compose.yaml` renamed to `docker-compose.yml`, and the broker now has a healthcheck,
  so `docker compose up -d --wait` returns only when RabbitMQ accepts connections.
