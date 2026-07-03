# WOLF-115 · Make `composer test` runnable without the `phpredis` PHP extension

| Field | Value |
|---|---|
| **Type** | Bug / Test environment |
| **Priority** | Blocker (prerequisite for every test-verified ticket) |
| **Status** | To Do |
| **Component** | Testing / CI |
| **Estimate** | 5 min |
| **Reporter** | Dylan |
| **Spec** | – |
| **Related** | Production-hardening batch (blocks WOLF-103 → WOLF-114 test verification) |

## Summary

Two independent gaps prevent `composer test` from running on any machine
without the `phpredis` PHP extension installed:

1. **`phpunit.xml` doesn't override `REDIS_CLIENT`.** It overrides the
   other Redis-adjacent env vars (`CACHE_STORE`, `SESSION_DRIVER`,
   `QUEUE_CONNECTION`, `BROADCAST_CONNECTION`) but not the client itself.
2. **`composer.json`'s `test` script routes through `php artisan test`.**
   Artisan boots the framework using `.env` (which sets
   `REDIS_CLIENT=phpredis`) *before* PHPUnit ever sees `phpunit.xml`, so
   the env override alone is not enough.

Fix both: force `REDIS_CLIENT=predis` in `phpunit.xml` and switch the
`composer test` script to invoke PHPUnit directly (`vendor/bin/phpunit`),
which reads `phpunit.xml` from the start and skips the artisan boot cycle
entirely. `predis/predis` is already required in `composer.json`.

## Background

Symptom, running `composer test` on macOS with the default PHP CLI:

```
Error: Class "Redis" not found
  at vendor/laravel/framework/src/Illuminate/Redis/Connectors/PhpRedisConnector.php:80
```

Stack terminates in `Application::boot()` → `TelescopeServiceProvider` →
`Redis::resolve()`. The failure is at framework boot, before any test
runs.

Existing `phpunit.xml` `<php>` block:

```xml
<env name="CACHE_STORE" value="array"/>
<env name="QUEUE_CONNECTION" value="sync"/>
<env name="SESSION_DRIVER" value="array"/>
<env name="BROADCAST_CONNECTION" value="null"/>
<env name="TELESCOPE_ENABLED" value="false"/>
```

`TELESCOPE_ENABLED=false` prevents Telescope from *recording*, but the
provider is still registered, and the Redis binding is still resolved
during boot. The missing override is `REDIS_CLIENT`.

`composer.json` already requires `predis/predis: ^3.4` — the swap
requires no new dependency.

The old `composer test` script:

```json
"test": [
    "@php artisan config:clear --ansi",
    "@php artisan test"
]
```

`artisan config:clear` was there to invalidate the cached config so that
`artisan test` would pick up phpunit env overrides. Neither step is
needed once we invoke PHPUnit directly.

## Failure modes this prevents

1. **Local test runs fail on any dev machine without phpredis installed.**
   Impacts on-boarding, my own iteration speed, and any handoff to a
   reviewer who tries to run the suite.
2. **CI cannot verify PRs without a custom base image.** Standard PHP
   images do not include `phpredis`; the workaround has to live somewhere,
   and code is the right place.
3. **Blocks the production-hardening batch.** Every subsequent ticket
   (WOLF-100 through WOLF-114) has an acceptance criterion that includes
   "tests pass." Without this fix, verification cannot happen locally.

## Acceptance criteria

- [x] `phpunit.xml`'s `<php>` block includes `<env name="REDIS_CLIENT" value="predis"/>`.
- [x] `composer.json`'s `test` script invokes `vendor/bin/phpunit` directly.
- [x] `composer test` completes successfully on a machine without the
      `phpredis` PHP extension installed (verified: 145 tests, 381
      assertions, 3.6s on PHP 8.4.20).
- [x] No change to production behavior — `.env` and `.env.production`
      still use `REDIS_CLIENT=phpredis`; the override applies only during
      the phpunit lifecycle.
- [x] No new dependencies added to `composer.json`.

## Out of scope

- **Installing `phpredis` on developer machines.** Would work but pushes
  environment setup onto every collaborator; the code fix is portable and
  requires no per-machine action.
- **Switching production `REDIS_CLIENT` to `predis`.** `phpredis` is
  faster in production because it's a C extension; keep the production
  default as-is.
- **Removing Telescope from the boot path in `testing` env.** Cleaner
  long-term but a bigger diff; this ticket's fix is a one-line addition
  that unblocks the batch immediately.

## Effort breakdown

| Step | Estimate |
|---|---|
| Edit `phpunit.xml` (add `REDIS_CLIENT` override) | 1 min |
| Edit `composer.json` (simplify `test` script) | 1 min |
| Run `composer test` to confirm | 3 min |

## Sequencing

**First ticket in the batch.** Blocks WOLF-103 through WOLF-114 from
having their acceptance criteria verified locally. Runs on its own branch
(`feature/wolf-115`), one-line diff, merges to master before anything
else.

## Notes

- The fix is intentionally test-env-only. Do not touch `.env` or
  `.env.production`.
- Verified during discovery of a Redis boot failure while executing
  WOLF-103 (add `declare(strict_types=1);`). The failure predates any
  code change — same error occurs on a clean checkout with no diff.
