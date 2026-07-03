# WOLF-102 · Standardize `DEVICE_DRIVER` env value; drop dead match branch

| Field | Value |
|---|---|
| **Type** | Tech Debt / Config drift |
| **Priority** | Medium |
| **Status** | To Do |
| **Component** | Config / Service provider |
| **Estimate** | 15 min |
| **Reporter** | Dylan |
| **Spec** | – |
| **Related** | Section 1 walkthrough (Bootstrap & request lifecycle) |

## Summary

`AppServiceProvider::register()` accepts two values for the same driver —
`'mqtt'` and `'esp32_mqtt'` — because the env files disagree on which string
to use. Standardize on one value across all env files and drop the redundant
match branch.

## Background

`app/Providers/AppServiceProvider.php:21-31`:

```php
return match (config('device.driver')) {
    'mqtt', 'esp32_mqtt' => new Esp32MqttDevice(...),
    default => new MockDevice,
};
```

Env-file audit:

| File | `DEVICE_DRIVER` |
|---|---|
| `.env` (local) | `esp32_mqtt` |
| `.env.docker` | `mock` |
| `.env.production` | `esp32_mqtt` |
| `.env.production.example` | `mqtt` |
| `config/device.php` docblock | `"mock", "esp32_mqtt"` |

The dual-alias match hides a real drift: the checked-in production example
tells a new operator to set `mqtt`, but the actual production env is
`esp32_mqtt`. A new operator who copies the example verbatim reaches the
same code path today only because the match accepts both — remove one branch
and their config silently falls back to `MockDevice` in production.

## Failure modes this prevents

1. **Silent fallback to `MockDevice` in production.** A future refactor that
   tightens the match to a single value would break any environment still
   using the other spelling. This ticket forces the alignment now, before
   that footgun ships.
2. **Interview-time footgun.** Reviewer greps for the match, asks "why two
   keys?" — the honest answer today ("env files disagree") is a weaker signal
   than "we standardized during hardening."

## Acceptance criteria

- [ ] All env files agree on **one** value for `DEVICE_DRIVER` — decision:
      `esp32_mqtt` (matches the currently deployed prod value; changing prod
      env content is riskier than changing example content).
- [ ] `.env.production.example` updated: `DEVICE_DRIVER=esp32_mqtt`.
- [ ] `config/device.php` docblock updated to name only the canonical value.
- [ ] `AppServiceProvider` match statement simplified to a single arm:
      `'esp32_mqtt' => new Esp32MqttDevice(...)`.
- [ ] `default => new MockDevice` behavior preserved (safety net for
      empty/missing env).
- [ ] No existing test regresses. If a test hardcodes `'mqtt'` as the driver
      value, update it or the test is broken.

## Out of scope

- **Introducing a `DeviceDriver` enum.** The driver string is a boundary
  value bound to env config, not a first-class domain concept. Enum would be
  over-engineering for two possible values. Interview-defensible.
- **Deprecation warning for the old value.** No known consumer sets `'mqtt'`
  after this ticket, so warning code would be dead on arrival.
- **Renaming `esp32_mqtt` to a more accurate value.** The name conflates
  "esp32 device family" with "mqtt transport"; a cleaner name would be `mqtt`,
  but changing the canonical value now would drag `.env.production` into scope
  and require a prod deploy. Not worth the risk pre-interview.

## Effort breakdown

| Step | Estimate |
|---|---|
| Edit 1 env example file + 1 config docblock | 5 min |
| Simplify match statement | 2 min |
| Run test suite | 5 min |

## Sequencing

Ships independently. No dependencies. Trivial rollback (revert the commit).

## Notes

- If a subsequent decision picks `mqtt` as the canonical value instead, the
  ticket flips: update `.env` and `.env.production` instead of the example,
  and change the match arm. Prod env change should be coordinated with a
  deploy window.
- The dual-alias was originally added during the ESP32 firmware planning
  when the naming was still in flux. Killing it now closes that transitional
  state.
