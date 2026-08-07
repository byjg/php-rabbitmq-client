# Changelog - Version 7.0

## Overview

Version 7.0 fixes a defect that killed healthy consumers on quiet queues, and stops a fleet of
consumers reconnecting in lockstep after a broker outage.

Part of the coordinated move of the byjg PHP components to 7.0.

## Breaking Changes

| Area | Before (6.x) | After (7.0) | Description |
|------|--------------|-------------|-------------|
| **Idle timeouts** | counted towards `max_attempts` | ignored | A `timeout` elapsing with no message no longer consumes a reconnection attempt. If you were relying on a short `timeout` plus a low `max_attempts` as an informal "stop after N idle periods" mechanism, that no longer exits — the consumer keeps waiting, which is what the documentation always described. |
| **Reconnect delays** | fixed 1, 2, 4, 8 … seconds | randomised between half and all of the computed delay | Reconnection timing is no longer reproducible. Anything asserting an exact reconnect schedule will need to assert a range. |

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

It also tore down and rebuilt a perfectly healthy connection on every timeout.

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

## Notes

- `ConnectorInterface` is unchanged; connectors written against 6.x need no modification.
- The dependency on `byjg/message-queue-client` remains `^6.0` and moves to `^7.0` once that package
  is released.

## Links

- [Documentation](https://github.com/byjg/php-rabbitmq-client/tree/main/docs)
- [Report Issues](https://github.com/byjg/php-rabbitmq-client/issues)
