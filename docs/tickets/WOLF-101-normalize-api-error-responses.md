# WOLF-101 · Normalize API error responses (JSON on `api/*`)


## Summary

`bootstrap/app.php`'s `withExceptions()` closure is empty, so exception
rendering falls through to Laravel's default heuristic. That heuristic
picks JSON vs HTML based on the incoming request's `Accept` header and
XHR flag. A client hitting `api/v1/*` without setting
`Accept: application/json` today gets HTML redirects (e.g.
`AuthenticationException` → 302 to `/login`) instead of a parseable
JSON envelope.

Tell Laravel that **any** request against `api/*` should be treated as
JSON-shaped, regardless of the `Accept` header, using
`Exceptions::shouldRenderJsonWhen()`. Laravel's built-in renderers
already produce the correct JSON shape for every relevant exception —
they just need `expectsJson()` to return true.

## Background

`bootstrap/app.php:32-34`:

```php
->withExceptions(function (Exceptions $exceptions): void {
    //
})
```

Concrete failure modes today (pre-ticket):

1. **Sanctum token expired mid-session.** iOS hits `api/v1/devices`
   without a fresh `Accept: application/json` header (or the request
   drops it mid-retry). Laravel's `AuthenticationException::render()`
   sees `expectsJson() == false`, redirects to
   `route('login')` → HTML 302. iOS decoder crashes trying to parse
   HTML as JSON.
2. **Validation failure on `api/*` POST.** Returns 422 JSON *if*
   `Accept` is set. If not, returns HTML form-error page.
3. **Route model binding miss.** `api/v1/geo-fences/{geoFence}` with
   a missing ID → `ModelNotFoundException` → 404 HTML page.
4. **`ThrottleRequestsException`** (WOLF-100 will start returning
   these) — currently no consistent JSON shape defined.

## Chosen approach: `shouldRenderJsonWhen()`

One line inside `withExceptions()` gates Laravel's entire renderer
pipeline into JSON mode for `api/*` requests:

```php
->withExceptions(function (Exceptions $exceptions): void {
    $exceptions->shouldRenderJsonWhen(function (Request $request, Throwable $e) {
        return $request->is('api/*') || $request->expectsJson();
    });
})
```

Effect:

| Exception | Response (before) | Response (after) |
|---|---|---|
| `AuthenticationException` | 302 → `/login` (HTML) | `401 {"message":"Unauthenticated."}` |
| `AuthorizationException` | Varies | `403 {"message":"This action is unauthorized."}` |
| `ValidationException` | Depends on `Accept` | `422 {"message":..., "errors":{...}}` |
| `NotFoundHttpException` / `ModelNotFoundException` | HTML 404 page | `404 {"message":"..."}` |
| `ThrottleRequestsException` | Depends on `Accept` | `429 {"message":"Too Many Requests"}` + `Retry-After` header |

## Why this approach vs per-exception render closures

Considered but rejected: writing an individual `->render(function
(AuthenticationException $e, Request $request) {...})` for each of
the five exception types.

- **Per-exception closures:** ~40 lines, each with its own status,
  message string, and JSON shape. Every future exception type needs
  its own closure to get JSON behavior. Message strings duplicated
  from Laravel's own defaults.
- **`shouldRenderJsonWhen`:** 5 lines. Zero maintenance for future
  exception types — Laravel's defaults cover them. Uses Laravel's
  own message strings (which are localized and match framework
  documentation).

The tradeoff we accept: Laravel's default messages verbatim.

- `AuthorizationException` → `"This action is unauthorized."`
  (not `"Forbidden."`)
- `NotFoundHttpException` → `"The route ... could not be found."`
  (verbose)

If a specific message needs customizing, a targeted `->render()`
closure can be added on top of the base rule without changing the
architecture.

## Failure modes this prevents

1. **iOS crash on Accept-header omission.** Any request to `api/*`
   returns JSON regardless of headers.
2. **Ambiguous validation surfacing.** iOS form controllers can rely
   on the `{message, errors}` shape being present for every 422.
3. **429 shape defined before WOLF-100 lands.** Prerequisite for the
   rate-limiter ticket.

## Acceptance criteria

- [ ] `bootstrap/app.php`'s `withExceptions()` calls
      `$exceptions->shouldRenderJsonWhen(...)` with the predicate
      shown above.
- [ ] New file `tests/Feature/Api/ErrorShapeTest.php` covers:
  - Unauthenticated GET on `api/v1/auth/user` (Sanctum-protected)
    returns `401` with a JSON body containing `message`.
  - POST on `api/v1/auth/register` with a missing required field
    returns `422` with a JSON body containing both `message` and
    `errors.<field>`.
  - GET on a non-existent nested resource (e.g. `/api/v1/geo-fences/999999`
    hit as an authenticated user) returns `404` with a JSON body
    containing `message`.
- [ ] All 3 tests explicitly omit `Accept: application/json` — the
      whole point is to prove the change works for clients that
      don't set it.
- [ ] `composer test` reports 145+3 = **148 tests** all passing.
- [ ] Existing web-route tests unmodified — no regression on Inertia
      flows. Sanity check: `AuthenticationTest`, `ProfileTest`,
      `DeviceManagementTest` still green.

## Out of scope

- **Adding a machine-readable `code` field** to error responses
  (e.g. `{"code": "auth.token.expired"}`). Defensible but the iOS
  client doesn't need it today.
- **Sentry / reporting overrides.** Rendering only.
- **`Retry-After` semantic changes.** WOLF-100 will exercise the
  429 shape; this ticket defines the envelope.
- **Web-route error UX** stays untouched — Inertia's own error
  flows continue to work.

## Effort breakdown

| Step | Estimate |
|---|---|
| Edit `bootstrap/app.php` (~5 lines) | 5 min |
| Write `tests/Feature/Api/ErrorShapeTest.php` (3 tests) | 15 min |
| Run test suite, verify no regressions | 10 min |

## Sequencing

First ticket in Wave 4. **Blocks WOLF-100** — the throttle ticket
needs a defined 429 shape before it starts returning 429s.

## Notes

- **Predicate ordering:** `$request->is('api/*')` first (cheaper,
  more common branch), then `expectsJson()` for the fallback.
- **Rollback:** trivial — delete the `shouldRenderJsonWhen()` call.
- **Framework version safety:** `shouldRenderJsonWhen()` is a
  Laravel 11+ API and this project runs Laravel 13, so the method
  is available.
