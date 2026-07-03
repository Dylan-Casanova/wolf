# WOLF-085 · Extract Geofence page route closure into `GeoFencePageController`


## Summary

`routes/web.php:37–44` renders the authenticated Geofence page via an
inline closure that pulls the current user's geofence and eager-loads
its pending scheduled trigger. Move the handler into a dedicated
single-action `GeoFencePageController::__invoke()`, matching the
pattern established in WOLF-084 for the Dashboard.

## Background

Current handler at `routes/web.php:37–44`:

```php
Route::get('/geofence', function () {
    $geofence = auth()->user()->geofence?->load('pendingScheduledTrigger');

    return Inertia::render('Geofence/Index', [
        'geofence' => $geofence,
        'server_now' => now()->toIso8601String(),
    ]);
})->name('geofence');
```

Same three smells as WOLF-106:
- Not unit-testable in isolation.
- Uses the `auth()` global helper instead of injected `Request`.
- Blocks route caching in configurations where closures aren't
  serializable.

## Why a separate `GeoFencePageController` and not a new method on the
existing `GeoFenceController`?

`GeoFenceController` is the JSON/API surface — every method returns
`JsonResponse` and it's mounted under `routes/web.php`'s
session-authenticated API routes. Adding an Inertia-returning `page()`
method there would mix two response contracts in one controller and
violate the single-responsibility read of the class ("this handles the
JSON API for geofences").

The alternative — one controller per resource, mixed response types —
is defensible in vanilla Laravel apps, but Inertia apps typically
split page controllers from API controllers because:
- Page controllers return `Inertia\Response` and shape props for the
  frontend.
- API controllers return `JsonResponse` and are consumed by both the
  browser via `laravel-echo`-driven interactions and by mobile.

Keeping them separate makes the responsibility split visible in the
folder listing.

## Failure modes / signal

Same as WOLF-106. Additionally: **naming inconsistency signal** — the
existing controller is `GeoFenceController` (camelCase-Fence). The new
controller matches: `GeoFencePageController`. Do not introduce
`GeofencePageController` (lowercase-fence) — that reads as a rename
and forces reviewer to check whether the two are related.

## Solution

New file `app/Http/Controllers/GeoFencePageController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class GeoFencePageController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $geofence = $request->user()->geofence?->load('pendingScheduledTrigger');

        return Inertia::render('Geofence/Index', [
            'geofence' => $geofence,
            'server_now' => now()->toIso8601String(),
        ]);
    }
}
```

Route becomes:

```php
Route::get('/geofence', GeoFencePageController::class)->name('geofence');
```

`routes/web.php` imports `use App\Http\Controllers\GeoFencePageController;`
alphabetically in the existing controller-imports block.

## Acceptance criteria

- [ ] `app/Http/Controllers/GeoFencePageController.php` exists with a
      single-action `__invoke(Request $request): Response` method that
      preserves the exact prop shape emitted by the current closure.
- [ ] Class name is `GeoFencePageController` (camelCase-Fence),
      consistent with existing `GeoFenceController`.
- [ ] `routes/web.php` `/geofence` route registers
      `GeoFencePageController::class` — no closure.
- [ ] Controller uses injected `Request`, not `auth()->user()`.
- [ ] `routes/web.php` alphabetically imports the new controller in
      its existing `use` block (between `GarageController` and
      `GeoFenceController`, since `GeoFenceP...` < `GeoFencePage...`
      — verify actual sort order and place appropriately).
- [ ] No prop-shape change: `geofence` remains model-or-null;
      `server_now` remains an ISO-8601 string.
- [ ] `composer test` reports 145/145 unchanged.

## Out of scope

- **Renaming the Inertia component from `Geofence/Index` to a
  simpler path.** Frontend naming decision, separate ticket.
- **Merging `GeoFenceController` (API) and `GeoFencePageController`
  (page) into a single controller with mixed response types.**
  Explicitly rejected in the design section above.
- **Adding a controller test.** Existing feature coverage under
  `tests/Feature/GeoFenceTest` exercises the URL path indirectly;
  a dedicated page-controller test would duplicate coverage.

## Effort breakdown

| Step | Estimate |
|---|---|
| Create `GeoFencePageController.php` | 5 min |
| Update `routes/web.php` | 3 min |
| Run test suite | 3 min |
| Verify import position (alphabetical) | 4 min buffer |

## Sequencing

Independent from every other ticket. Ships on its own branch.
Follows WOLF-106 (Dashboard extraction) using the same pattern —
useful review signal to land both together (or in immediate
succession) so the pattern is visible to reviewers.

## Notes

- **Why `__invoke`?** Same rationale as WOLF-106 — single-action
  page.
- **Naming risk:** `GeoFencePageController` might read as "a
  controller for GeoFencePage" (a hypothetical entity) rather than
  "the page controller for GeoFence." Considered
  `GeoFenceDashboardController` but that overloads the "dashboard"
  term; considered `GeoFenceIndexPageController` — verbose. Landing
  on `GeoFencePageController` as the least-bad tradeoff. If a
  reviewer strongly prefers a rename, easy follow-up.
