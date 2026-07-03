# WOLF-091 · Extract GeoFence validation into Form Requests; move policy checks to the request boundary


## Summary

`GeoFenceController` inlines `$request->validate([...])` calls in five
methods, with identical rule blocks duplicated between `store` and
`update`. Extract validation into dedicated Form Request classes and
**move the `$this->authorize(...)` calls from the controller into each
Form Request's `authorize()` method**. The result: the controller's
job shrinks to orchestration — the request boundary handles both
validation and authorization before the controller method executes.

## Background

Current controller signatures:

| Method | Has `->validate()`? | Has `authorize()` (post WOLF-109)? |
|---|---|---|
| `store` | ✅ | ❌ (creating, no existing model to authorize) |
| `update` | ✅ (rules identical to `store`) | ✅ `update` |
| `destroy` | ❌ | ✅ `delete` |
| `toggle` | ❌ | ✅ `update` |
| `check` | ✅ (lat, lng) | ✅ `update` |
| `estimate` | ✅ (lat, lng — same rules as `check`) | ✅ `view` |
| `scheduleTrigger` | ✅ (minutes, origin_lat, origin_lng) | ✅ `update` |
| `cancelScheduledTrigger` | ❌ | ✅ `update` |

Duplication:
- `store` and `update` share **6 identical validation rules** (200-lat/lng
  bounds × 6 fields).
- `check` and `estimate` share **2 identical rules** (lat, lng bounds).

## Failure modes / signal

1. **Rule drift.** `store` and `update` currently validate the same
   fields; if the range on `north_lat` ever tightens on `update` but
   not `store`, invalid data lands. Form Requests give one file to
   change.
2. **Fat controllers.** Every `Request::validate([...])` call adds
   6–8 lines to the controller. The controller's job should be
   orchestration, not schema authoring.
3. **Authorization pattern split.** Post-WOLF-109 the controller calls
   `$this->authorize()`. In idiomatic Laravel, `FormRequest::authorize()`
   is preferred because it runs **before** validation, saving the
   validation cycle when authorization already fails. Moving it there
   consolidates the boundary.
4. **Testability.** Form Requests unit-test cleanly — instantiate
   the class, call `rules()`, assert shape. Controller inline
   validation requires HTTP boot.

## Solution

**Five new Form Requests** under `app/Http/Requests/`:

| Class | Rules | `authorize()` |
|---|---|---|
| `StoreGeoFenceRequest` | 6 lat/lng field rules | `true` (no target model; controller still returns 409 if a geofence already exists) |
| `UpdateGeoFenceRequest` | Same 6 rules | `$this->user()->can('update', $this->route('geoFence'))` |
| `CheckGeoFenceRequest` | 2 rules (`lat`, `lng`) | `$this->user()->can('update', $this->route('geoFence'))` |
| `EstimateGeoFenceRequest` | Same 2 rules | `$this->user()->can('view', $this->route('geoFence'))` |
| `ScheduleTriggerRequest` | 3 rules (`minutes`, `origin_lat`, `origin_lng`) | `$this->user()->can('update', $this->route('geoFence'))` |

**Controller methods after refactor:**

```php
public function update(UpdateGeoFenceRequest $request, GeoFence $geoFence): JsonResponse
{
    $geoFence->update($request->validated());

    return response()->json($geoFence);
}
```

**Three methods keep `$this->authorize()` in the controller** —
they take no request body so a Form Request would exist purely for a
one-line authorize call, which is over-engineering:

- `destroy` → `$this->authorize('delete', $geoFence);`
- `toggle` → `$this->authorize('update', $geoFence);`
- `cancelScheduledTrigger` → `$this->authorize('update', $geoFence);`

## Behavior nuance — authorization runs before validation

`FormRequest::authorize()` returning false throws `AuthorizationException`
**before** `rules()` runs. That means a malformed body from an
unauthorized user gets a 403 (unauthorized) rather than a 422
(validation error). This is a **feature, not a bug** — it prevents
leaking field-shape information through validation errors to callers
who shouldn't be able to touch the resource in the first place.

The existing feature tests assert 403 on cross-user access with valid
bodies; they'll still pass. No test asserts "422 for unauthorized user
with invalid body" (grep-verified).

## Why `CheckGeoFenceRequest` and `EstimateGeoFenceRequest` are separate
despite identical rules

They differ in authorization: check uses `update` (may mutate armed
state + fire servo), estimate uses `view` (pure read). Extracting a
shared base for identical rules would introduce inheritance to save
2 lines; not worth it. Two independent files keep the responsibility
one-file-one-endpoint.

## Store's "already exists" check stays in the controller

`store()` today short-circuits with `409 { "message": "Geofence
already exists." }` if the user already owns one. This is **domain
state validation, not request validation** — the request itself is
well-formed. Moving it into `withValidator()` would work but blurs
the distinction between "your input was wrong" (422) and "your input
was fine but current state forbids it" (409). Keep it in the
controller.

## Acceptance criteria

- [ ] Five files exist under `app/Http/Requests/GeoFence/`:
  - `StoreGeoFenceRequest.php`
  - `UpdateGeoFenceRequest.php`
  - `CheckGeoFenceRequest.php`
  - `EstimateGeoFenceRequest.php`
  - `ScheduleTriggerRequest.php`
- [ ] Each file has `rules(): array` and `authorize(): bool` as declared
      in the table above.
- [ ] `GeoFenceController` methods with a request body accept the
      corresponding Form Request via type hint; use
      `$request->validated()` instead of `$request->validate(...)`.
- [ ] `GeoFenceController` no longer contains any inline
      `$request->validate([...])` call for the five refactored
      methods.
- [ ] The four methods now handled by Form Request's `authorize()`
      (update, check, estimate, scheduleTrigger) no longer call
      `$this->authorize(...)` — the Form Request handles it.
- [ ] The three no-body methods (destroy, toggle, cancelScheduledTrigger)
      continue to call `$this->authorize(...)` from the controller.
- [ ] `store()` retains its `409 Geofence already exists.` check
      (documented above).
- [ ] `composer test` reports 145/145 unchanged.
- [ ] Existing `tests/Feature/GeoFenceTest.php` cross-user 403
      assertions continue to pass.
- [ ] Existing validation-error tests continue to pass with the
      same 422 shape.

## Out of scope

- **Naming the folder differently.** `app/Http/Requests/GeoFence/`
  matches existing convention (`app/Http/Requests/Auth/` already
  exists for `LoginRequest`).
- **Adding a `ToggleRequest` / `DestroyRequest` / `CancelScheduledTriggerRequest`.**
  These have no body to validate; a Form Request purely for a
  one-line `authorize()` is more indirection than value.
- **Introducing custom validation rule objects.** The current rules
  are inline strings/arrays; no rule is complex enough to justify
  its own class.
- **Move the 409 "already exists" check into `store()`'s Form Request.**
  Discussed above — state validation belongs in the controller/service.
- **Return-shape normalization.** WOLF-101 handles the API error
  envelope.

## Effort breakdown

| Step | Estimate |
|---|---|
| Create 5 Form Request files | 15 min |
| Refactor `GeoFenceController` (5 method signatures + delete inline `validate` and inline `authorize`) | 15 min |
| Run test suite | 5 min |
| Verify no test regressions on 422/403 shapes | 10 min |

## Sequencing

Third ticket in Wave 2, after WOLF-109. **Blocking prerequisite** for
WOLF-112 (`GeoFenceService` extraction) — the service will accept the
Form Request's `validated()` array, so consolidating validation at
the boundary first keeps the service PR focused on orchestration.

## Notes

- **Why `route('geoFence')` and not `route('geo_fence')`?** The route
  parameter is `{geoFence}` in `routes/web.php` (camelCase, matches
  the model). Verify at implementation.
- **`FormRequest::user()` vs `auth()->user()`** — `FormRequest::user()`
  is a helper delegating to the auth guard; matches Laravel's own
  documentation examples. Fine either way; using the helper here.
- **Namespacing** — `App\Http\Requests\GeoFence\{Name}` groups all
  five in one folder. Sibling of the existing
  `App\Http\Requests\Auth\LoginRequest`.
