# WOLF-100 · Apply `device-capture` rate limiter to garage + stream trigger routes


## Summary

`AppServiceProvider::boot()` defines a named rate limiter `device-capture`
intended to cap user-triggered device actions at 10 requests/minute,
but the limiter is never applied to any route. Wire it into the three
endpoints it was designed to protect: `POST /garage/trigger` (web),
`POST /stream/start` (web), and `POST /api/v1/garage/trigger` (mobile).

## Scope refresh

The original ticket named only the two web routes. A later survey of
`routes/api.php` surfaced a **third** endpoint that maps to the same
`GarageController::trigger` via the mobile Sanctum-authenticated
surface: `POST /api/v1/garage/trigger`. Applying the throttle only to
the web routes would leave the mobile app free to bypass the limit for
the identical physical action (servo trigger). All three endpoints
receive the throttle.

No mobile-facing `/stream/start` exists today.

## Background

In `app/Providers/AppServiceProvider.php:42-44`:

```php
RateLimiter::for('device-capture', function (Request $request) {
    return Limit::perMinute(10)->by($request->user()?->id ?: $request->ip());
});
```

Grep across `routes/` confirms no route uses `throttle:device-capture`
today. The limiter is dormant. The three write endpoints that hit
external hardware (garage servo × 2 routes, stream start × 1 route)
have no rate-limiting at all.

## Failure modes this prevents

1. **Servo abuse.** An authenticated user (or a bug in the UI) holds
   the trigger button and issues N commands/second to the ESP32,
   wearing the servo and flooding Reverb with `ServoTriggered` events.
2. **Stream-storm.** A native app retry loop spins up N concurrent
   stream sessions per second, exhausting device connections and
   populating stale `streams` rows the cleanup command has to sweep.
3. **Silent DoS on the device.** MQTT command topic backs up, latency
   spikes for legitimate users. No 429 signal is ever surfaced to
   the client.
4. **Cross-surface bypass.** Throttling only web would let mobile
   send an unlimited firehose against the same servo. Applied to
   both.

## Acceptance criteria

- [x] `routes/web.php`'s `POST /garage/trigger` has
      `->middleware('throttle:device-capture')`.
- [x] `routes/web.php`'s `POST /stream/start` has
      `->middleware('throttle:device-capture')`.
- [x] `routes/api.php`'s `POST /api/v1/garage/trigger` has
      `->middleware('throttle:device-capture')`.
- [x] Existing behavior for `POST /stream/{stream}/stop` unchanged
      (no throttle — the stop path should always succeed).
- [x] The limiter definition itself is unchanged in
      `AppServiceProvider`.
- [x] New tests confirm the 11th request within a minute returns
      429:
  - `GarageControllerTest::garage_trigger_is_rate_limited_after_10_per_minute`
  - `StreamControllerTest::stream_start_is_rate_limited_after_10_per_minute`
  - `Api\V1\GarageTest::api_garage_trigger_is_rate_limited_after_10_per_minute`
- [x] Existing `GarageControllerTest` and `StreamControllerTest`
      pass without modification — below-threshold traffic is
      unaffected.
- [x] Full suite: `composer test` reports **151 tests** (148
      baseline + 3 new), all green.

## Out of scope

- **Extending the limiter to `POST /geo-fences/*/check`.** The check
  endpoint is polled from OS-geofence crossings and has its own
  semantic frequency; adding a throttle there without measuring
  native call rates could regress the arming flow. Separate
  follow-up if metrics show it's needed.
- **Introducing per-device rate limits** (rather than per-user).
  The current data model allows multiple devices per user, but the
  abuse vector is at the user-command level, not the device level.
  Deferred as YAGNI.
- **Throttle-limit tuning (10/min).** Placeholder value; will tune
  based on real-world usage patterns after WOLF-100 lands.
- **Custom 429 body shape.** WOLF-101 handles JSON error rendering
  for `api/*` uniformly; the default `{"message":"Too Many
  Requests"}` envelope is sufficient for both web (Inertia toast) and
  mobile clients.

## Effort breakdown

| Step | Estimate |
|---|---|
| Wire `throttle:device-capture` onto 3 route definitions | 5 min |
| Write 3 rate-limit tests (2 web + 1 api) | 15 min |
| Run full test suite | 5 min |

## Sequencing

Second ticket in Wave 4. Depends on WOLF-101 conceptually for the
429 JSON shape, but code changes are independent.

## Notes

- **Limiter key selection:** `->by($request->user()?->id ?: $request->ip())`
  — authenticated requests key by user ID; unauthenticated requests
  key by IP. The three routes are all behind auth, so the IP
  fallback is effectively unreachable but preserved for parity with
  the definition.
- **Test caveat:** `Cache::store('array')` (per `phpunit.xml`) resets
  between tests via test-level Application reboot, so counters don't
  leak between test cases.
- **Interview-defensible answer to "why 10/min?":** placeholder from
  the original scaffold; would tune based on real-user data once
  telemetry exists. Not a load-bearing number today.
