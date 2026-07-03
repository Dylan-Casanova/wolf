# WOLF-100 · Apply `device-capture` rate limiter to garage + stream trigger routes

| Field | Value |
|---|---|
| **Type** | Bug / Security control gap |
| **Priority** | High |
| **Status** | To Do |
| **Component** | HTTP routing / Rate limiting |
| **Estimate** | 20–30 min |
| **Reporter** | Dylan |
| **Spec** | – |
| **Related** | Section 1 walkthrough (Bootstrap & request lifecycle) |

## Summary

`AppServiceProvider::boot()` defines a named rate limiter `device-capture` intended
to cap user-triggered device actions at 10 requests/minute, but the limiter is
never applied to any route. Wire it into the two endpoints it was designed to
protect: `POST /garage/trigger` and `POST /stream/start`.

## Background

In `app/Providers/AppServiceProvider.php:42-44`:

```php
RateLimiter::for('device-capture', function (Request $request) {
    return Limit::perMinute(10)->by($request->user()?->id ?: $request->ip());
});
```

Grep across `routes/` confirms no route uses `throttle:device-capture`:

```
$ grep -rn "throttle:device-capture" routes/
(no results)
```

The limiter is dormant. The two user-triggered write endpoints that hit external
hardware — the garage servo and the stream start — have no rate-limiting at all
today. A held-down button, a runaway iOS retry loop, or a malicious authenticated
client can flood MQTT with commands and spam Reverb subscribers.

## Failure modes this prevents

1. **Servo abuse.** An authenticated user or a bug in the UI holds the trigger
   button and issues N commands/second to the ESP32, wearing the servo and
   flooding Reverb with `ServoTriggered` events.
2. **Stream-storm.** A native app retry loop spins up N concurrent stream
   sessions per second, exhausting device connections and populating stale
   `streams` rows the cleanup command has to sweep.
3. **Silent DoS on the device.** MQTT command topic backs up, latency spikes
   for legitimate users. No 429 signal is ever surfaced to the client.

## Acceptance criteria

- [ ] `POST /garage/trigger` returns 429 with a `Retry-After` header after the
      11th request from the same authenticated user within a rolling minute.
- [ ] `POST /stream/start` returns 429 with a `Retry-After` header after the
      11th request from the same authenticated user within a rolling minute.
- [ ] Below-threshold traffic (≤10/min) is unaffected — existing `GarageControllerTest`
      and `StreamControllerTest` continue to pass without modification.
- [ ] A new test asserts the 11th request within a minute returns 429 for
      each endpoint.
- [ ] The limiter keys by `user->id` for authenticated requests; IP fallback
      is not exercised on these routes (they sit behind `auth`), but the
      behavior is left intact for parity with the definition.
- [ ] No change to the API contract of successful requests (still 200/201
      with the existing JSON body).

## Out of scope

- **Extending the limiter to `POST /geo-fences/*/check`.** The check endpoint
  is polled from OS-geofence crossings and has its own semantic frequency;
  adding a throttle there without measuring native call rates could regress
  the arming flow. Separate follow-up if metrics show it's needed.
- **Applying the limiter to the API `v1/*` mobile-auth endpoints.** They live
  in `routes/api.php` and belong in the API layer review (Section 11).
- **Introducing per-device rate limits** (rather than per-user). The current
  data model allows multiple devices per user, but the abuse vector is at the
  user-command level, not the device level. Deferred as YAGNI.

## Effort breakdown

| Step | Estimate |
|---|---|
| Add `throttle:device-capture` to two route definitions in `routes/web.php` | 5 min |
| Write two feature tests (one per route) that assert 429 on the 11th call | 20 min |
| Run full test suite | 5 min |

## Sequencing

Ships independently. No dependencies on other tickets.

## Notes

- The rate-limiter definition itself is correct and does not need changing.
- Consider adding a `Log::warning('Rate limit hit', ['route' => ..., 'user' => ...])`
  when the throttle triggers so we get a signal in production before support
  tickets arrive. Left out of acceptance criteria; can be added in review if
  reviewers agree.
- 10/min was the author's original guess. Real usage will inform whether to
  tune it — capture in `INTERVIEW-QA.md` as an anticipated question.
