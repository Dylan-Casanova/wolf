# WOLF-XXX · Split `geo_fences.is_active` into derived attribute before iOS Phase 4

| Field | Value |
|---|---|
| **Type** | Tech Debt / Refactor |
| **Priority** | Blocker for iOS Phase 4 |
| **Status** | To Do |
| **Component** | Geofence backend (Laravel) |
| **Estimate** | 60–90 min |
| **Reporter** | Dylan |
| **Spec** | `docs/superpowers/specs/2026-06-16-geofence-is-active-schema-split.md` |
| **Related** | iOS spec `docs/superpowers/plans/2026-06-14-wolf-ios-react-native-spec.md` (Phase 4 — OS geofencing) |

## Summary

`geo_fences.is_active` is currently overloaded by two surfaces with conflicting semantics: the web treats it as "a timer is pending," and the iOS app's Phase 4 will treat it as "live OS-geofence check is armed." Split the column into a derived attribute (`is_active`) computed from `live_check_armed` (native) **OR** `pendingScheduledTrigger != null` (web) so the two surfaces stop stomping on each other.

This **must land before iOS Phase 4 ships to production.**

## Failure modes this prevents (from spec)

1. **Double-fire.** Web schedules 30-min timer; native OS geofence crosses at minute 10 and fires the servo, then the scheduled job fires the servo a second time at minute 30 because the pending row still exists.
2. **Cross-surface UI lying.** Native arms via `/toggle`; web reads `pending_scheduled_trigger == null` and shows "Disarmed" even though the native side is armed.
3. **Cancel-then-cross race.** Web cancels its timer (sets `is_active=false`); seconds later a native OS-geofence cross hits `/check` and sees `is_active=false`, so the cancel on the web silently disarmed the native side.

## Acceptance criteria

- [ ] Migration adds `live_check_armed BOOLEAN NOT NULL DEFAULT 0` and drops `is_active`
- [ ] Migration is run **only after** confirming no rows in `scheduled_geofence_triggers` have `status='pending'` (avoids false-positive carryover from the existing `is_active=1` semantics)
- [ ] `GeoFence` model exposes `is_active` as a derived accessor: `live_check_armed || pendingScheduledTrigger !== null`
- [ ] `live_check_armed` is in `$fillable` and cast to `boolean`; `is_active` is not in `$fillable` anymore
- [ ] `GeoFenceController::toggle()` writes `live_check_armed`, not `is_active`
- [ ] `GeoFenceController::check()` gates on `live_check_armed` and clears `live_check_armed` after fire
- [ ] `GeoFenceController::scheduleTrigger()` **no longer writes** `is_active=true` — the accessor derives it
- [ ] `GeoFenceController::cancelScheduledTrigger()` **no longer writes** `is_active=false`
- [ ] `TriggerScheduledGeofenceJob::handle()` **no longer writes** `is_active=false` after fire
- [ ] `GeoFenceFactory` has `active()` state that sets `live_check_armed=true` (not `is_active=true`)
- [ ] All existing `tests/Feature/GeoFenceTest.php` tests still pass
- [ ] **New test:** `scheduleTrigger` + `/check` from inside the fence triggers servo **exactly once** (not once per surface)
- [ ] **New test:** `/toggle` to arm, `/check` outside → no servo; `/check` inside → servo fires
- [ ] **New test:** scheduled trigger fires → `is_active` derives to `false` on next read
- [ ] Web Geofence page still shows "Scheduled · Opens in MM:SS" when a timer is pending
- [ ] Web Geofence page still shows "Disarmed" when neither armed surface is true

## Out of scope

- Any frontend changes — both surfaces already read `is_active` from the JSON response and the accessor keeps that key intact.

## Effort breakdown

| Step | Estimate |
|---|---|
| Migration | 10 min |
| Model + accessor + casts | 10 min |
| Controller methods (4 to edit) | 20 min |
| Factory + existing test updates | 20 min |
| New collision/regression tests | 30 min |

## Sequencing

1. ✅ Web theme — done, no dependency on this split
2. ⏳ iOS Phase 3 (geofence setup) — safe to build without the split
3. 🚨 **This ticket** — land in `wolf` as its own PR before Phase 4 starts
4. ⏳ iOS Phase 4 (OS geofencing) — depends on this being merged

## Why not earlier

The refactor touches the hot path of the most common user actions on a currently-shipping flow. Doing it preemptively, with no native `/check` consumer yet, takes on real regression risk for a payoff that doesn't materialize until Phase 4. Better to bundle landing with the start of Phase 4 planning so both surfaces are exercised together before either ships.

## Implementation reference

Full code (migration body, model snippet, each controller method, factory, test names) is in `docs/superpowers/specs/2026-06-16-geofence-is-active-schema-split.md`. Implementer should treat that spec as the source of truth for exact code shapes.
