# WOLF-083 · Extract Dashboard route closure into `DashboardController`

## Summary

`routes/web.php:20–35` renders the authenticated Dashboard via an
inline closure that loads devices, maps them to a presentation shape,
and eager-loads the geofence's pending scheduled trigger. Move the
handler into a dedicated single-action `DashboardController::__invoke()`.
Closures with business logic in the routes file are a common
junior-code smell — reviewers flag them because they resist testing,
IDE navigation, and dependency injection.

## Background

Current handler at `routes/web.php:20–35`:

```php
Route::get('/dashboard', function () {
    $user = auth()->user();
    $devices = $user->devices()->get()->map(fn ($device) => [
        'id' => $device->id,
        'name' => $device->name,
        'device_id' => $device->device_id,
        'type' => $device->type->value,
        'is_online' => $device->is_online,
    ]);

    return Inertia::render('Dashboard', [
        'devices' => $devices,
        'geofence' => $user->geofence?->load('pendingScheduledTrigger'),
        'server_now' => now()->toIso8601String(),
    ]);
})->name('dashboard');
```

Problems:

- **Not testable in isolation.** A feature test can `get('/dashboard')`
  and assert response shape, but you can't unit-test the mapping logic
  without booting HTTP.
- **`auth()` helper hides the dependency.** Global helper access is
  the anti-pattern equivalent of a static call — no seam for DI.
- **Blocks route caching in some configurations.** Laravel's route
  cache serializes route definitions; closures containing use()
  scopes are excluded. This app doesn't currently cache routes, but
  the door is closed on the option while the closure exists.
- **Reviewer signal.** Inline closures for anything beyond
  `fn () => Inertia::render('Static')` read as "this got written in a
  hurry and never revisited." The Welcome route on line 15
  (`fn () => Inertia::render('Welcome')`) is fine — it's a
  no-parameters one-liner. Dashboard is not.

## Failure modes / signal

1. **Regression coverage gap.** The device-mapping shape is untested
   as a unit. If someone changes `type => $device->type->value` to
   `type => $device->type`, the frontend breaks and the only place
   it surfaces is a Playwright/manual test.
2. **Hidden coupling to `auth()`.** Cannot inject a fake user or a
   fake device query without an HTTP request.
3. **Interview-visible smell.** Reviewer opens `routes/web.php`
   first (top-down navigation) and immediately sees a 16-line closure
   next to one-liners.

## Solution

New file `app/Http/Controllers/DashboardController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $user = $request->user();

        $devices = $user->devices()->get()->map(fn ($device) => [
            'id' => $device->id,
            'name' => $device->name,
            'device_id' => $device->device_id,
            'type' => $device->type->value,
            'is_online' => $device->is_online,
        ]);

        return Inertia::render('Dashboard', [
            'devices' => $devices,
            'geofence' => $user->geofence?->load('pendingScheduledTrigger'),
            'server_now' => now()->toIso8601String(),
        ]);
    }
}
```

Route becomes:

```php
Route::get('/dashboard', DashboardController::class)->name('dashboard');
```

`routes/web.php` imports `use App\Http\Controllers\DashboardController;`
alphabetically in the existing controller-imports block.

## Acceptance criteria

- [x] `app/Http/Controllers/DashboardController.php` exists with a
      single-action `__invoke(Request $request): Response` method that
      preserves the exact prop shape emitted by the current closure.
- [x] `routes/web.php` `/dashboard` route registers
      `DashboardController::class` (single-action shorthand) — no
      controller method name string, no closure.
- [x] The controller uses the injected `Request` for the current
      user, not the `auth()` global helper.
- [x] `routes/web.php` alphabetically imports the new controller in
      its existing `use` block.
- [x] No prop-shape change:
  - `devices` remains an array of the same 5 keys in the same order.
  - `geofence` remains the eager-loaded model instance (or null).
  - `server_now` remains an ISO-8601 string.
- [x] Verified: 145/145 tests pass, 381 assertions unchanged.

## Out of scope

- **Extracting the device-mapping into an API Resource
  (`DeviceResource`).** That's WOLF-111; leave the array literal
  in place here and swap it out in that ticket.
- **Introducing a `DashboardService`.** No orchestration logic
  today beyond a couple of Eloquent calls; a service layer is
  over-engineering. If Dashboard grows to load more subsystems,
  revisit.
- **Adding a test for `DashboardController` directly.** The
  existing dashboard smoke test in `tests/Feature/AuthenticationTest`
  (`test_authenticated_users_can_visit_the_dashboard`) already
  exercises the happy path. A unit test for `__invoke` would
  duplicate coverage without adding signal.
- **Renaming the Inertia component from `Dashboard` to
  `Dashboard/Index`.** Consistency with `Geofence/Index` is a
  Wave 5 decision.

## Effort breakdown

| Step | Estimate |
|---|---|
| Create `DashboardController.php` | 5 min |
| Update `routes/web.php` | 3 min |
| Run test suite | 3 min |
| Verify prop-shape unchanged (manual eyeball or smoke) | 4 min |

## Sequencing

Independent from every other ticket. Ships on its own branch.
Immediately followed by WOLF-107 (the sibling Geofence page closure)
using the same pattern.

## Notes

- **Why `__invoke` instead of `index`?** Dashboard is a single-action
  page, not part of a REST resource. `__invoke` communicates that
  intent and matches the existing `HealthController::class` pattern
  in this repo. If dashboard ever grows a second action (POST to
  something, PATCH the layout), split then.
- **Why inject `Request` instead of using `auth()->user()`?** The
  `auth()` helper resolves through the container — same end result
  but it's a hidden dependency. `Request` is already the standard
  entry point for other controllers (`GeoFenceController`, etc.).
- **Return type:** `Inertia\Response`. Matches Inertia's actual return
  type; enables IDE completion downstream.
