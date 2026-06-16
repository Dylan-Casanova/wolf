# Schema split: `geo_fences.is_active` → derived attribute

**Status:** Prerequisite ticket — **must land BEFORE iOS Phase 4 (OS geofencing) ships to production.**

**Date filed:** 2026-06-16
**Related design specs:**
- `2026-06-16-perspective-theme-design.md` ("Risks and open questions")
- iOS spec: `2026-06-14-wolf-ios-react-native-spec.md` (Phase 4 — OS geofencing)

## Problem

The `geo_fences.is_active` column is currently driven by two different surfaces with conflicting semantic meanings.

| Surface | Treats `is_active` as | Writes it from |
|---|---|---|
| **Web** | "A timer is pending" | `scheduleTrigger()` (sets true), `cancelScheduledTrigger()` (sets false), `TriggerScheduledGeofenceJob::handle()` (sets false on fire) |
| **Native** (Phase 4, future) | "Live OS-geofence check is armed" | `/toggle` (flips), `/check` (sets false on fire) |

This works while only one surface is in production. The moment the iOS app's Phase 4 ships (OS geofence registration + `/check` calls), the two semantics collide.

## Failure modes that surface when native ships

1. **Double-fire.** Web user schedules a 30-min timer (`is_active=true`, pending row exists). At minute 10 they drive through the perimeter. Native OS geofence fires → `/check` → server sees `is_active && contains(pos)` → triggers servo → sets `is_active=false`. Scheduled job still sits in Redis. At minute 30 it fires, atomic claim succeeds (row is still `pending`), servo triggers a **second time**.

2. **Cross-surface UI lying.** Native user arms via `/toggle`. `is_active` becomes true with no scheduled trigger row. Web user opens `/geofence` — reads `pending_scheduled_trigger`, finds null, shows "Disarmed." UI is lying about the actual armed state.

3. **Cancel-then-cross race.** Web user cancels timer (`is_active=false`). Three seconds later native OS geofence detects boundary cross, posts `/check`, server sees `is_active=false`, no-op. Functionally fine but conceptually wrong — the web's cancel just disarmed the native side.

## Fix: derived `is_active` from two separate concerns

### 1. Migration

```php
// database/migrations/YYYY_MM_DD_split_geo_fences_is_active.php
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('geo_fences', function (Blueprint $table) {
            $table->boolean('live_check_armed')->default(false)->after('west_lng');
        });

        // Best-effort migration of existing is_active values to live_check_armed
        // (since today's is_active is dominantly written by the web's scheduled
        // trigger path, this MIGHT set live_check_armed=true for fences that
        // only have a pending timer — acceptable trade-off; see note below)
        DB::statement('UPDATE geo_fences SET live_check_armed = is_active');

        Schema::table('geo_fences', function (Blueprint $table) {
            $table->dropColumn('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('geo_fences', function (Blueprint $table) {
            $table->boolean('is_active')->default(false)->after('west_lng');
        });
        DB::statement('UPDATE geo_fences SET is_active = live_check_armed');
        Schema::table('geo_fences', function (Blueprint $table) {
            $table->dropColumn('live_check_armed');
        });
    }
};
```

**Migration note:** Best to run this migration **before any user has a pending scheduled trigger** to avoid the false-positive `live_check_armed=true` carryover. Easy way: run during off-hours after confirming no pending triggers exist (`SELECT COUNT(*) FROM scheduled_geofence_triggers WHERE status='pending'`).

### 2. Model — derived accessor

```php
// app/Models/GeoFence.php

protected $appends = ['is_active'];

protected $fillable = [
    'user_id',
    'north_lat', 'south_lat', 'east_lng', 'west_lng',
    'address_lat', 'address_lng',
    'live_check_armed',  // ← replaces 'is_active'
];

protected $casts = [
    // ... existing casts
    'live_check_armed' => 'boolean',
];

// Derived accessor — true when ANY armed state is active
public function getIsActiveAttribute(): bool
{
    return $this->live_check_armed
        || $this->pendingScheduledTrigger !== null;
}
```

### 3. Controller updates

**`GeoFenceController::toggle()`** — used by native to arm/disarm live check:
```php
$geoFence->update(['live_check_armed' => ! $geoFence->live_check_armed]);
return response()->json(['is_active' => $geoFence->fresh()->is_active]);
```

**`GeoFenceController::check()`** — used by native on OS geofence cross:
```php
$inside = $geoFence->live_check_armed
    && $geoFence->contains($validated['lat'], $validated['lng']);

if ($inside) {
    // ... existing servo trigger ...
    $geoFence->update(['live_check_armed' => false]);  // ← was 'is_active' => false
}
```

**`GeoFenceController::scheduleTrigger()`** — used by web to schedule a timer:
```php
// REMOVE this line:
// $geoFence->update(['is_active' => true]);
//
// No write needed — the existence of the pending ScheduledGeofenceTrigger
// row is what makes is_active derive to true.

return response()->json([
    'scheduled_trigger_id' => $trigger->id,
    'scheduled_at' => $trigger->scheduled_at->toIso8601String(),
    'fence' => ['is_active' => true],  // accessor still returns true
]);
```

**`GeoFenceController::cancelScheduledTrigger()`**:
```php
ScheduledGeofenceTrigger::where('geo_fence_id', $geoFence->id)
    ->where('status', ScheduledGeofenceTrigger::STATUS_PENDING)
    ->update(['status' => ScheduledGeofenceTrigger::STATUS_CANCELLED]);

// REMOVE this line:
// $geoFence->update(['is_active' => false]);

return response()->json(['fence' => ['is_active' => false]]);  // accessor returns false
```

**`TriggerScheduledGeofenceJob::handle()`**:
```php
// After atomic claim succeeds and servo fires:
// REMOVE this line:
// $fence->update(['is_active' => false]);
//
// No write needed — the trigger row's status='fired' update makes
// pendingScheduledTrigger() return null, which makes is_active derive false.
```

### 4. Test updates

The existing `tests/Feature/GeoFenceTest.php` references `is_active` in several places:

```php
->create(['is_active' => false])     // factory state
->where('is_active', false)          // assertions
$fence->fresh()->is_active           // accessor reads
```

These continue to work because `is_active` is still accessible — it's just derived now. But there's a subtle gotcha: tests that **write** `is_active` (e.g., factory states like `'is_active' => true`) will silently fail to set anything since it's no longer in `$fillable`.

Update the `GeoFence` factory and any tests that previously did `create(['is_active' => true])` to use `'live_check_armed' => true` instead.

```php
// database/factories/GeoFenceFactory.php
public function active(): static
{
    return $this->state(fn () => ['live_check_armed' => true]);
}
```

### 5. Frontend (no changes required)

Both surfaces continue to read `is_active` from the JSON response — the accessor serializes it. The web's `pending_scheduled_trigger` field still works the same way. No client-side changes needed in either `wolf` or `wolf-ios`.

## Acceptance criteria

- [ ] Migration runs cleanly on a fresh DB and on existing DBs with data
- [ ] All existing `tests/Feature/GeoFenceTest.php` tests still pass (existing test grid validates the behavior holistically)
- [ ] **New test:** `scheduleTrigger` followed by `/check` from a position inside the fence triggers servo only ONCE (not twice from the schedule too)
- [ ] **New test:** `/toggle` to arm, then `/check` outside the perimeter does NOT call the servo; then `/check` inside DOES (verifies `live_check_armed` gate)
- [ ] **New test:** Scheduled trigger fires → `is_active` derives to false on next read (verifies the accessor)
- [ ] Web Geofence page still shows "Scheduled · Opens in 12:34" when a timer is pending
- [ ] Web Geofence page still shows "Disarmed" when no timer pending AND `live_check_armed` is false

## Estimated effort

60-90 minutes for an implementer who has context on the geofence subsystem. Roughly:

- Migration: 10 min
- Model + accessor: 10 min
- Controller methods (4 to edit): 20 min
- Factory + test updates: 20 min
- New tests for the collision scenarios: 30 min

## When to do this

**Before iOS Phase 4 ships to production.**

Concrete sequencing:

1. ✅ Web theme (this session) — does not depend on this split
2. ⏳ iOS Phases 3 (geofence setup) — does NOT exercise `/check`; safe to build without the split
3. 🚨 **Schema split (this ticket)** — land before Phase 4 starts
4. ⏳ iOS Phase 4 (OS geofencing) — relies on the cleaner schema being in place

The split should land in `wolf` as its own PR, with the iOS Phase 4 planning starting after it's merged.

## Why not "just do it now"

This work is a small but real refactor that touches a hot path (the controller methods that drive the most common user actions). It carries non-zero regression risk to the currently-shipping web flow. Doing it preemptively, while there's no native `/check` consumer yet, means taking on that risk for benefit that doesn't materialize until Phase 4 ships.

Better to bundle it with Phase 4's planning + smoke-testing cycle so the split is exercised end-to-end (web + native concurrently) before either is in production. That's the moment the work pays off.
