# WOLF-066 — Split `geo_fences.is_active` into native arm flag + derived web state

**Type:** Refactor / Schema change
**Priority:** High (blocks native app go-live)
**Branch:** `feature/wolf-066`
**Status:** Implementation complete, awaiting review + prod migration window

---

## Summary

Replace the single `geo_fences.is_active` column with a real `live_check_armed`
column (owned by the native client) and a derived `is_active` accessor that
also factors in pending scheduled triggers (owned by the web client). This
prevents either surface from clobbering the other's armed state.

## Background

`is_active` was being written by two independent code paths:

1. **Native** — toggled directly by the rider's phone to arm the live "am I
   inside the fence?" check. The phone then calls `/check` with its GPS and
   the server fires the servo if the user is inside.
2. **Web** — flipped to `true` whenever the rider scheduled a delayed trigger
   from the dashboard, and back to `false` when the trigger fired or was
   cancelled.

Because both paths wrote the same column, either side could silently clear
the other's state:

- A native toggle could turn off a fence that the web had armed for a
  scheduled trigger.
- A scheduled trigger firing would clear `is_active`, disarming any live
  check the native side had set.

This isn't theoretical — once the iOS app starts arming fences in production
alongside scheduled web triggers, double-writes will produce ghost-disarm bugs
that are extremely hard to reproduce.

## Solution

Split ownership of the two states:

| State                    | Stored as                                        | Written by             |
| ------------------------ | ------------------------------------------------ | ---------------------- |
| Native live-check armed  | `geo_fences.live_check_armed` (real boolean col) | `toggle`, `check`      |
| Web scheduled-trigger    | `scheduled_geofence_triggers` row (status=pending) | `scheduleTrigger`, `cancelScheduledTrigger`, `TriggerScheduledGeofenceJob` |
| `is_active` (API field)  | **derived** accessor `live_check_armed \|\| pendingScheduledTrigger !== null` | — (read-only)          |

`is_active` stays in JSON responses via `$appends`, so no client-side change
is required on iOS or the web frontend.

## Changes

### Schema
- `database/migrations/2026_06_17_000000_split_geo_fences_is_active.php`
  - Adds `live_check_armed BOOLEAN NOT NULL DEFAULT false`
  - Backfills `live_check_armed = is_active`
  - Drops `is_active`

### Model
- `app/Models/GeoFence.php`
  - `live_check_armed` added to `$fillable` and `$casts`
  - `is_active` removed from real attributes; added to `$appends`
  - New `getIsActiveAttribute()` derives from `live_check_armed` OR
    `pendingScheduledTrigger`

### Controllers / Jobs
- `app/Http/Controllers/GeoFenceController.php`
  - `toggle()` writes `live_check_armed`
  - `check()` gates on `live_check_armed && contains(...)` and clears
    `live_check_armed` on fire
  - `scheduleTrigger()` no longer writes the fence row
  - `cancelScheduledTrigger()` no longer writes the fence row; returns
    `$geoFence->fresh()->is_active` (now derived)
- `app/Jobs/TriggerScheduledGeofenceJob.php`
  - Removed `$fence->update(['is_active' => false])` — the atomic claim that
    flips the trigger row to `status=fired` is enough; the accessor derives
    to false on the next read.

### Tests
- `tests/Feature/GeoFenceTest.php`
  - Updated 2 existing tests to use pending-trigger creation rather than the
    `active()` factory state where that was the actual driver
  - Added 3 new tests:
    - `test_check_does_not_fire_without_toggle_even_with_pending_trigger`
      (double-fire prevention — pending web trigger must not arm native check)
    - `test_toggle_arms_live_check_so_check_inside_fires`
      (native arm path)
    - `test_is_active_derives_from_pending_trigger_and_live_check_armed`
      (accessor truth table)

### Factory
- `database/factories/GeoFenceFactory.php`
  - `definition()` defaults `live_check_armed => false`
  - `active()` state sets `live_check_armed => true`

## Test results

```
PASS  Tests\Feature\GeoFenceTest
Tests:    145 passed (381 assertions)
```

## Deployment runbook

**Run the migration only after the queue is drained and there are no pending
scheduled triggers**, otherwise active scheduled fences silently lose their
armed flag in the backfill.

```sql
-- Pre-flight check, must return 0:
SELECT COUNT(*) FROM scheduled_geofence_triggers WHERE status = 'pending';
```

Steps:
1. Choose a low-traffic window
2. Pause the worker / wait for queue drain
3. Verify the SQL above returns 0
4. Deploy code + run `php artisan migrate`
5. Resume worker
6. Smoke test: arm a fence from native, arm a scheduled trigger from web,
   confirm `is_active` reads `true` in both cases via `GET /geofences`

## Rollback

The migration drops the old column, so rollback is a code revert plus a
recovery migration that adds `is_active` back and backfills from
`live_check_armed`. Document expectation: rollback loses any pending scheduled
trigger context but does not break the data model.

## Out of scope

- iOS client changes — none required (API contract unchanged)
- Web frontend changes — none required (API contract unchanged)
- Historical `scheduled_geofence_triggers` cleanup — separate ticket

## Acceptance criteria

- [x] Backend tests pass (145/145)
- [x] `is_active` continues to appear in `GET /geofences` payloads
- [x] Native toggle → `/check` inside fence → servo fires, `live_check_armed`
      clears, `is_active` reflects derived state
- [x] Web `scheduleTrigger` → `is_active` true → trigger fires or is
      cancelled → `is_active` derives to false without writing the fence row
- [ ] Code review approval
- [ ] Successful prod migration in a window with 0 pending triggers
