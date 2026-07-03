# WOLF-101 · Normalize API error responses (JSON on `api/*`)

| Field | Value |
|---|---|
| **Type** | Bug / API contract |
| **Priority** | High |
| **Status** | To Do |
| **Component** | HTTP error handling / API layer |
| **Estimate** | 45–60 min |
| **Reporter** | Dylan |
| **Spec** | – |
| **Related** | Section 1 walkthrough; Section 11 (API layer); iOS client at `../../../wolf-ios` |

## Summary

`bootstrap/app.php`'s `withExceptions()` closure is empty, so every exception
falls through to Laravel's default renderer. For `web` routes that renders
HTML (correct). For `api/*` routes the response shape depends on the framework
default per-exception heuristic — some cases return HTML redirects (e.g.
`AuthenticationException` → redirect to `/login`), which the iOS client cannot
consume. Standardize a JSON error envelope for all `api/*` responses.

## Background

In `bootstrap/app.php:30-32`:

```php
->withExceptions(function (Exceptions $exceptions): void {
    //
})
```

No render/report overrides. Concrete symptoms:

- `AuthenticationException` on `api/v1/*` returns a 302 redirect to `/login`
  rather than `401 {"message":"Unauthenticated."}`.
- `ValidationException` on `api/*` returns a mix of shapes depending on the
  `Accept` header rather than a guaranteed `{"message":..., "errors":{...}}`.
- `ModelNotFoundException` (route model binding miss) returns generic HTML
  404 rather than `{"message":"Not found."}`.
- `ThrottleRequestsException` (after WOLF-100 lands) will inherit whatever the
  default is unless we standardize now.

The iOS client parses JSON error envelopes exclusively. Non-JSON responses
crash its decoder before the human-readable error can be surfaced.

## Failure modes this prevents

1. **iOS crash on session expiry.** Sanctum token expires mid-session; iOS hits
   `api/v1/devices`; server returns HTML `/login` redirect; decoder crashes.
2. **Ambiguous validation surfacing.** iOS form controllers can't reliably
   discover `errors.<field>` shape and fall back to a generic "Something went
   wrong" message.
3. **No structured 429 to back off from.** Post-WOLF-100 the rate limiter fires
   but the iOS client has no consistent envelope to read `Retry-After` from.

## Acceptance criteria

- [ ] `withExceptions()` closure adds render overrides for at least:
  - `AuthenticationException` → JSON 401 `{"message":"Unauthenticated."}` when
    `$request->is('api/*')` or `$request->expectsJson()`.
  - `AuthorizationException` → JSON 403 `{"message":"Forbidden."}` under same
    condition.
  - `ValidationException` → JSON 422 with the standard `{message, errors}`
    envelope (Laravel default is already close; guarantee it applies for
    `api/*`).
  - `ModelNotFoundException` and `NotFoundHttpException` → JSON 404
    `{"message":"Not found."}`.
  - `ThrottleRequestsException` → JSON 429 with a `Retry-After` header and
    `{"message":"Too many requests.","retry_after":N}` body.
- [ ] All existing web-route error behavior is preserved (Inertia flows still
      redirect / render Blade as before). Guardrail: run existing feature
      tests unmodified — they must pass.
- [ ] New tests in `tests/Feature/Api/ErrorShapeTest.php`:
  - Unauthenticated hit on an `api/v1/*` route returns `401 JSON`, not `302`.
  - Validation failure on an `api/*` POST returns `422 JSON` matching the
    `{message, errors}` shape.
  - Non-existent model on route-model-bound `api/*` route returns `404 JSON`.
- [ ] `docs/tickets/WOLF-100-apply-device-capture-throttle.md`'s 429 tests are
      updated to also assert JSON body shape (once this ticket lands).

## Out of scope

- **Structuring error codes beyond `message`/`errors`.** Adding stable
  machine-readable `code` strings per exception is nice-to-have and belongs in
  a follow-up once iOS surface tells us which codes are worth branching on.
- **Reporting overrides** (e.g. Sentry integration). Reporting is a Section 15
  concern; this ticket only touches rendering.
- **Changing `web` route behavior.** Inertia already handles its own error UX
  via session flashes and 419 CSRF-refresh; do not regress that path.

## Effort breakdown

| Step | Estimate |
|---|---|
| Write the render closures in `bootstrap/app.php` | 20 min |
| Add feature tests for each exception → JSON | 25 min |
| Verify no regression on Inertia web flows (`AuthenticationTest`, `ProfileTest`) | 10 min |

## Sequencing

Depends on nothing. Should merge **before** WOLF-100 so the 429 shape is defined
before we start returning 429s.

## Notes

- Prefer `$request->is('api/*') || $request->expectsJson()` as the JSON gate;
  matches how Laravel itself decides between HTML and JSON.
- Consider whether to include a stable `type` field (e.g. `type: "authentication"`)
  in the envelope for iOS to branch on. Deferred to out-of-scope pending iOS input.
- Extract the render closures into `app/Exceptions/Handlers.php` if the
  closure gets over ~60 lines — otherwise keep it inline in `bootstrap/app.php`
  to preserve the Laravel 11+ "no god-kernel" pattern.
