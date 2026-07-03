# WOLF- · Introduce `GeoFencePolicy`; replace 7 duplicated ownership checks



## Summary

`GeoFenceController` duplicates the same ownership check across 7
different methods:

```php
if ($geoFence->user_id !== $request->user()->id) {
    return response()->json(['message' => 'Forbidden.'], 403);
}
```

This is a textbook Policy candidate. Introduce `App\Policies\GeoFencePolicy`
with three conventional methods (`view`, `update`, `delete`), route the
7 controller sites through `$this->authorize(...)`, and delete the
inline checks. Also add the `AuthorizesRequests` trait to the base
`Controller` so the pattern is available to every future controller.

## Background

Seven duplicated ownership guards in `GeoFenceController`:

| Method | Line | Current guard |
|---|---|---|
| `update` | 47 | inline `user_id` check |
| `destroy` | 67 | inline `user_id` check |
| `toggle` | 78 | inline `user_id` check |
| `check` | 89 | inline `user_id` check |
| `estimate` | 119 | inline `user_id` check |
| `scheduleTrigger` | 142 | inline `user_id` check |
| `cancelScheduledTrigger` | 191 | inline `user_id` check |

Same rule, seven copies. If the rule ever changes (e.g. an admin role
is added), it changes in seven places.

The base `Controller` class (`app/Http/Controllers/Controller.php`) is
empty — Laravel 11+ removed the default trait bundle. That means
`$this->authorize(...)` does not exist on any controller today. Adding
the trait to the base Controller once makes the pattern available
project-wide and matches the convention every Laravel policy tutorial
assumes.

Policy auto-discovery: Laravel 11+ resolves `App\Models\GeoFence` →
`App\Policies\GeoFencePolicy` by naming convention. No
`AuthServiceProvider` registration required (and none exists in this
project — verified: `app/Providers/` contains only `AppServiceProvider`
and `TelescopeServiceProvider`).

## Failure modes / signal

1. **Rule drift.** Copy-paste guards silently diverge under refactor.
   One method gets a typo (`user_id ==` instead of `!==`), another
   gets updated to check an admin flag, a third stays as-is — three
   different rules, one repo.
2. **Testability.** Policies unit-test cleanly (a `User` and a
   `GeoFence`, one assertion); inline guards require booting HTTP.
3. **Interview signal.** Reviewer opens `GeoFenceController`, sees
   the repeated 3-line block seven times, and immediately asks
   "where's the Policy?" Having one already answers the question
   before it's asked.

## Solution

**New file** `app/Policies/GeoFencePolicy.php`:

```php
<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\GeoFence;
use App\Models\User;

class GeoFencePolicy
{
    public function view(User $user, GeoFence $geoFence): bool
    {
        return $user->id === $geoFence->user_id;
    }

    public function update(User $user, GeoFence $geoFence): bool
    {
        return $user->id === $geoFence->user_id;
    }

    public function delete(User $user, GeoFence $geoFence): bool
    {
        return $user->id === $geoFence->user_id;
    }
}
```

**Base controller** — add the trait so `$this->authorize()` works
everywhere:

```php
// app/Http/Controllers/Controller.php
namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

abstract class Controller
{
    use AuthorizesRequests;
}
```

**Controller call sites** — replace each inline guard with a single
`$this->authorize(<verb>, $geoFence)` call. Verb mapping:

| Controller method | Policy method |
|---|---|
| `update` | `update` |
| `destroy` | `delete` |
| `toggle` | `update` (state mutation) |
| `check` | `update` (may fire servo, may clear armed flag) |
| `estimate` | `view` (read-only distance calculation) |
| `scheduleTrigger` | `update` (creates related row that affects fence's derived state) |
| `cancelScheduledTrigger` | `update` (mutates the pending trigger row) |

## Behavior change (worth calling out)

The inline guards return **`403 { "message": "Forbidden." }`**. The
`AuthorizesRequests` trait throws `AuthorizationException`, which
Laravel's default exception renderer converts to **`403 { "message":
"This action is unauthorized." }`** for JSON requests.

The message text changes but the status code stays 403. The two known
consumers of these endpoints are:
- The web frontend — reads a generic "forbidden" toast either way.
- The iOS client — reads status code first, message second.

No test currently asserts the exact string `"Forbidden."` on these
endpoints (grep-verified: no `assertJson(['message' => 'Forbidden.'])`
against `/geo-fences/*`). So no test breaks from the message change.

WOLF-101 will normalize this envelope shape further; this ticket
consciously accepts the intermediate change.

## Acceptance criteria

- [ ] `app/Policies/GeoFencePolicy.php` exists with three methods:
      `view`, `update`, `delete`, each returning
      `$user->id === $geoFence->user_id`.
- [ ] `app/Http/Controllers/Controller.php` uses
      `Illuminate\Foundation\Auth\Access\AuthorizesRequests`.
- [ ] `GeoFenceController` no longer contains any
      `if ($geoFence->user_id !== $request->user()->id)` check.
- [ ] Each of the 7 methods invokes `$this->authorize(<verb>,
      $geoFence)` per the mapping table above, before any other
      logic.
- [ ] `composer test` reports 145/145 unchanged.
- [ ] Manual regression: a `curl` request from user A hitting user
      B's fence still returns 403 (verified by an existing feature
      test in `tests/Feature/GeoFenceTest.php` that asserts 403 on
      cross-user access — must continue to pass).

## Out of scope

- **Admin bypass.** No admin role exists on this endpoint today.
  When it does, add a `before` method to the policy that returns
  `true` for `$user->is_admin`. Deferred until the use case exists.
- **Testing the policy in isolation.** The 7 controller-level
  feature tests already exercise the ownership path
  transitively. A unit test on the policy itself would duplicate
  coverage without adding signal.
- **Migrating away from the inline JSON envelope.** WOLF-101 handles
  API error shape normalization across the whole
  `api/*` surface.
- **Applying policies to other controllers** (Device, Stream, etc.).
  Those controllers have less duplication or different auth models
  (admin-only for devices, ownership implicit for streams via route
  binding scope). Case-by-case if it becomes a pattern.

## Effort breakdown

| Step | Estimate |
|---|---|
| Create `GeoFencePolicy` | 5 min |
| Add trait to base `Controller` | 2 min |
| Rewrite 7 `GeoFenceController` methods (delete 3 lines + add 1) | 15 min |
| Run test suite | 3 min |
| Verify cross-user 403 test still green | 5 min |

## Sequencing

Second ticket in Wave 2. Independent from WOLF-110 (Form Requests) and
WOLF-111 (Resources) — those touch different concerns in the same
controller. **Blocking prerequisite** for WOLF-112 (`GeoFenceService`
extraction) — the service will call `$this->authorize(...)` via the
controller before delegating to the service.

## Notes

- **Why not use `Gate::authorize()` directly** in the controller and
  skip the trait? Idiomatic Laravel uses `$this->authorize()` — the
  trait shorthand is what interviewers expect. Adding it once to the
  base Controller is a two-line change with codebase-wide payoff.
- **Why 3 policy methods for a rule that's identical everywhere?**
  The convention (`view`/`update`/`delete`) is the natural extension
  seam. If ownership diverges by verb later — e.g. an admin can
  `view` but not `delete` — the shape is already there. Cost today:
  three lines of code.
- **Auto-discovery vs explicit registration.** Laravel 11+ resolves
  policies via `App\Policies\{ModelName}Policy` convention. No
  `AuthServiceProvider` is required or present. If a future teammate
  adds one and defines a `$policies` array, they must include
  `GeoFence => GeoFencePolicy` explicitly — noted here so it's not
  a rediscovery moment.
