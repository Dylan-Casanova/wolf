# WOLF-093 · Introduce API Resources for `GeoFence`, `ScheduledGeofenceTrigger`, and `Device`

## Summary

Controllers currently `response()->json($eloquentModel)` for GeoFence
endpoints and inline a hand-written array shape for Device in
`DashboardController`. Introduce dedicated `JsonResource` classes:
`GeoFenceResource`, `ScheduledGeofenceTriggerResource`, and
`DeviceResource`. Wire them into their current call sites while
preserving the existing wire format exactly.

## Scope adjustment from original plan

The original plan called for **three** resources: GeoFence, Device,
Stream. Adjusted:

- **`ScheduledGeofenceTriggerResource` added** — GeoFenceResource
  emits the eager-loaded `pending_scheduled_trigger` relation; the
  clean pattern is to wrap that relation in its own Resource too.
- **`StreamResource` deferred** — Stream is currently exposed only
  as `['stream_id' => $stream->id]` in `StreamController::start`.
  There is no callsite where a full `StreamResource` would improve
  anything today. WOLF-113 (`StreamService`) can create it if a
  need materializes; creating unused code now is a smell.

Net: **still three files**, different roster than originally planned.

## Background

Current serialization sites:

| Callsite | What's returned today |
|---|---|
| `GeoFenceController::index` | `response()->json($geofence ? [$geofence] : [])` — raw model, all fields + `is_active` accessor |
| `GeoFenceController::store` | `response()->json($geofence, 201)` — raw model |
| `GeoFenceController::update` | `response()->json($geoFence)` — raw model |
| Dashboard / Geofence pages (Inertia) | `$user->geofence?->load('pendingScheduledTrigger')` — raw model, includes relation |
| `DashboardController::__invoke` | Hand-mapped array of 5 device fields |
| `DeviceController::index` | Raw `Device::with('user')->latest()->get()` — includes user relation |

Problems:

1. **No single source of truth for the wire shape.** Field renames on
   the model silently propagate to every consumer, and a rename
   nobody wants (e.g. adding an internal `secret_` prefix) leaks to
   iOS.
2. **Model-level hidden/appends flags leak across contexts.** `is_active`
   is appended globally on the GeoFence model, so ANY serialization
   emits it. Fine today; fragile as we add other contexts (admin
   view, mobile view) that might want different subsets.
3. **Dashboard's inline array is a de facto ad-hoc resource.**
   Extracting it to `DeviceResource` centralizes the mapping and
   lets other endpoints (mobile, admin) reuse the same shape.
4. **Interview signal.** Fully-loaded senior review looks at
   `response()->json($model)` and asks "where's the Resource?" —
   or worse, "how do you prevent leaking columns the model exposes
   internally?"

## Solution

**Three new files under `app/Http/Resources/`:**

### `GeoFenceResource`

Shape mirrors the current model JSON exactly to prevent breakage:

```php
public function toArray(Request $request): array
{
    return [
        'id' => $this->id,
        'user_id' => $this->user_id,
        'north_lat' => $this->north_lat,
        'south_lat' => $this->south_lat,
        'east_lng' => $this->east_lng,
        'west_lng' => $this->west_lng,
        'address_lat' => $this->address_lat,
        'address_lng' => $this->address_lng,
        'live_check_armed' => $this->live_check_armed,
        'is_active' => $this->is_active,
        'created_at' => $this->created_at,
        'updated_at' => $this->updated_at,
        'pending_scheduled_trigger' => ScheduledGeofenceTriggerResource::make(
            $this->whenLoaded('pendingScheduledTrigger'),
        ),
    ];
}
```

### `ScheduledGeofenceTriggerResource`

```php
public function toArray(Request $request): array
{
    return [
        'id' => $this->id,
        'geo_fence_id' => $this->geo_fence_id,
        'scheduled_at' => $this->scheduled_at,
        'origin_lat' => $this->origin_lat,
        'origin_lng' => $this->origin_lng,
        'origin_distance_meters' => $this->origin_distance_meters,
        'status' => $this->status,
        'created_at' => $this->created_at,
        'updated_at' => $this->updated_at,
    ];
}
```

### `DeviceResource`

```php
public function toArray(Request $request): array
{
    return [
        'id' => $this->id,
        'name' => $this->name,
        'device_id' => $this->device_id,
        'type' => $this->type->value,
        'is_online' => $this->is_online,
        'last_seen_at' => $this->last_seen_at,
        'user' => $this->whenLoaded('user'),
    ];
}
```

`whenLoaded('user')` mirrors the current admin-index behavior — user
appears only when the relation was eager-loaded, so
`DashboardController` (no `->with('user')`) gets no user field, and
`DeviceController::index` (which does eager-load) keeps the
current shape.

## Wire-format preservation

Explicit acceptance criteria: **no field additions, removals, or
renames** at any current callsite. Verification path:

1. Existing feature tests are the shape witnesses.
   `tests/Feature/GeoFenceTest.php` `assertJson([...])` calls freeze
   the expected keys.
2. If any test breaks on a missing field, the Resource is wrong —
   fix the Resource, not the test.
3. `is_active`, `created_at`, `updated_at` explicitly included.

## Callsite wiring

| Callsite | Before | After |
|---|---|---|
| `GeoFenceController::index` | `response()->json($g ? [$g] : [])` | `GeoFenceResource::collection($g ? [$g] : [])` |
| `GeoFenceController::store` | `response()->json($g, 201)` | `GeoFenceResource::make($g)->response()->setStatusCode(201)` |
| `GeoFenceController::update` | `response()->json($g)` | `GeoFenceResource::make($g)` |
| `DashboardController::__invoke` | Inline array map | `DeviceResource::collection($user->devices)` |
| `DeviceController::index` | Raw Eloquent collection | `DeviceResource::collection($devices)` (with `->load('user')` still explicit) |

`GeoFenceController::toggle` / `check` / `estimate` /
`scheduleTrigger` / `cancelScheduledTrigger` return non-model
payloads (`['is_active' => bool]`, `['triggered' => bool, 'distance_meters' => int]`,
etc.) — those stay as `response()->json(...)`.

## Acceptance criteria

- [ ] `app/Http/Resources/GeoFenceResource.php`,
      `app/Http/Resources/ScheduledGeofenceTriggerResource.php`,
      `app/Http/Resources/DeviceResource.php` all exist and follow
      the shape declared above.
- [ ] Every callsite in the wiring table above uses its Resource;
      no callsite returns a raw Eloquent model or hand-mapped array.
- [ ] `composer test` reports 145/145 unchanged — the wire shape
      guarantee is enforced by existing `assertJson` assertions.
- [ ] Grep verification: no `response()->json($geoFence` or
      `response()->json($geofence` remains for a full-model return
      in `GeoFenceController`.
- [ ] `DashboardController::__invoke` no longer contains the inline
      device-mapping array literal.
- [ ] `GeoFence` model still uses `$appends = ['is_active']` —
      Resource explicitly serializes it either way; we don't need to
      change the model.

## Out of scope

- **`StreamResource`.** No current consumer for a full stream
  serialization; deferred to WOLF-113 if needed.
- **Removing fields.** Trimming `user_id` from GeoFenceResource
  (arguably redundant since the caller is the owner) is defensible
  but breaks wire compat with iOS. Separate ticket if we want it.
- **API versioning namespace.** Living under
  `App\Http\Resources\` (flat) rather than `App\Http\Resources\V1\`.
  Version-aware resource design is a Wave-N concern.
- **`UserResource`.** Only exposed via
  `HandleInertiaRequests::share()` today; touching that surface
  is a separate refactor (would affect every Inertia page prop).

## Effort breakdown

| Step | Estimate |
|---|---|
| Create 3 Resource classes | 15 min |
| Wire callsites (5 sites across 3 controllers) | 15 min |
| Run test suite; fix any shape mismatches | 15 min |

## Sequencing

Last ticket in Wave 2. Independent from WOLF-109 and WOLF-110 in code
but conceptually completes the "controller boundary" cleanup —
validation (WOLF-110), authorization (WOLF-109), and now response
shaping (WOLF-111). WOLF-112 (`GeoFenceService`) will assume all three
patterns are in place.

## Notes

- **Why `whenLoaded()` for relations?** Prevents N+1s. If a caller
  forgets to eager-load `pendingScheduledTrigger`, the field is
  omitted rather than triggering a per-item query.
- **`Resource::make(null)`** returns `null` — safe for the
  `whenLoaded` case where the relation wasn't eager-loaded, and for
  the `?->` chain from Inertia.
- **`GeoFenceResource::collection([])`** returns an empty array
  correctly — matches current `[]` return on empty index.
