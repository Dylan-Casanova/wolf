# WOLF-095 · Extract `GeoFenceService` — controller becomes a thin orchestrator

## Summary

With authorization (WOLF-109), request validation (WOLF-110), and
response shaping (WOLF-111) already moved to the boundary, the
controller's remaining sin is that it *drives* the business logic
inline: distance math, MQTT-triggered servo firing, DB transactions
with row locks, job dispatching, and pending-trigger state
transitions.

Extract every domain operation into a `GeoFenceService`. After the
refactor, each controller method reads as three lines: extract input,
delegate to service, wrap in Resource. The controller no longer knows
about Eloquent queries, `DeviceInterface`, DB transactions, or
`TriggerScheduledGeofenceJob`.

## Background

Current `GeoFenceController` post-WOLF-111 orchestrates domain work
in 6 of its 9 methods:

| Method | Domain work still in the controller |
|---|---|
| `store` | Duplicate check + Eloquent create via user relation |
| `update` | Eloquent update |
| `destroy` | Eloquent delete |
| `toggle` | Boolean flip on `live_check_armed` |
| `check` | Distance calc, inside check, MQTT servo trigger, `live_check_armed` clear |
| `estimate` | Distance calc, mph→minutes conversion, config read |
| `scheduleTrigger` | `DB::transaction` + row lock + cancel-existing + create-new + job dispatch |
| `cancelScheduledTrigger` | Bulk update to `status=cancelled` |
| `index` | Read-only Eloquent traversal (stays; trivial) |

The controller's constructor doesn't inject any service today —
`DeviceInterface` is method-injected only into `check`. That's a
pattern smell (mixing method-level and constructor-level DI).

## Failure modes / signal

1. **Domain logic can't be reused.** If a scheduled job wants the
   same "trigger servo" flow that `check` runs, it has to re-implement
   it. Today `TriggerScheduledGeofenceJob` already does exactly this —
   duplicate device lookup, duplicate servo trigger. WOLF-112 gives
   both callers one method to share.
2. **Controller isn't unit-testable without HTTP boot.** Distance
   calculations are a math operation; testing them via a full HTTP
   round-trip is overkill.
3. **Transaction and locking scope belongs with the operation, not
   the HTTP handler.** Today `scheduleTrigger`'s row-lock semantics
   live in a controller method. Moving to the service localizes the
   guarantee.
4. **Interview signal.** A senior reviewer opens the controller,
   sees 200 lines of mixed responsibilities, and asks "where's the
   service?" Having one already reframes the conversation from
   *"why is this in the controller?"* to *"walk me through the
   service's transaction boundary."*

## Solution

**New file** `app/Services/GeoFenceService.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\DeviceInterface;
use App\Enums\DeviceType;
use App\Jobs\TriggerScheduledGeofenceJob;
use App\Models\GeoFence;
use App\Models\ScheduledGeofenceTrigger;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class GeoFenceService
{
    public function __construct(private DeviceInterface $device) {}

    public function createFor(User $user, array $data): GeoFence
    {
        return $user->geofence()->create($data);
    }

    public function update(GeoFence $geoFence, array $data): GeoFence
    {
        $geoFence->update($data);

        return $geoFence;
    }

    public function delete(GeoFence $geoFence): void
    {
        $geoFence->delete();
    }

    public function toggle(GeoFence $geoFence): void
    {
        $geoFence->update(['live_check_armed' => ! $geoFence->live_check_armed]);
    }

    /**
     * Evaluate a geo point against a fence. If armed and inside,
     * fire the servo and clear the armed flag.
     *
     * @return array{triggered: bool, distance_meters: int}
     */
    public function check(User $user, GeoFence $geoFence, float $lat, float $lng): array
    {
        $distance = $geoFence->distanceFromCenter($lat, $lng);
        $inside = $geoFence->live_check_armed && $geoFence->contains($lat, $lng);

        if ($inside) {
            $this->triggerServoForUser($user);
            $geoFence->update(['live_check_armed' => false]);
        }

        return [
            'triggered' => $inside,
            'distance_meters' => (int) round($distance),
        ];
    }

    /**
     * @return array{distance_miles: float, estimated_minutes: int, assumed_speed_mph: int}
     */
    public function estimate(GeoFence $geoFence, float $lat, float $lng): array
    {
        $distanceMeters = $geoFence->distanceFromCenter($lat, $lng);
        $distanceMiles = $distanceMeters / 1609.34;
        $speedMph = (int) config('wolf.estimated_arrival_mph', 35);
        $estimatedMinutes = (int) max(1, round(($distanceMiles / $speedMph) * 60));

        return [
            'distance_miles' => round($distanceMiles, 1),
            'estimated_minutes' => $estimatedMinutes,
            'assumed_speed_mph' => $speedMph,
        ];
    }

    /**
     * Schedule a delayed trigger. Serializes cancel+create per fence
     * under a row lock; dispatches the job after commit.
     */
    public function scheduleTrigger(
        GeoFence $geoFence,
        int $minutes,
        float $originLat,
        float $originLng,
    ): ScheduledGeofenceTrigger {
        $trigger = DB::transaction(function () use ($geoFence, $minutes, $originLat, $originLng) {
            GeoFence::whereKey($geoFence->id)->lockForUpdate()->first();

            ScheduledGeofenceTrigger::where('geo_fence_id', $geoFence->id)
                ->where('status', ScheduledGeofenceTrigger::STATUS_PENDING)
                ->update(['status' => ScheduledGeofenceTrigger::STATUS_CANCELLED]);

            return ScheduledGeofenceTrigger::create([
                'geo_fence_id' => $geoFence->id,
                'scheduled_at' => now()->addMinutes($minutes),
                'origin_lat' => $originLat,
                'origin_lng' => $originLng,
                'origin_distance_meters' => $geoFence->distanceFromCenter($originLat, $originLng),
                'status' => ScheduledGeofenceTrigger::STATUS_PENDING,
            ]);
        });

        // Dispatch outside the transaction so a rollback can't leave
        // a job queued against a row that doesn't exist.
        TriggerScheduledGeofenceJob::dispatch($trigger->id)->delay($trigger->scheduled_at);

        return $trigger;
    }

    public function cancelScheduledTrigger(GeoFence $geoFence): void
    {
        ScheduledGeofenceTrigger::where('geo_fence_id', $geoFence->id)
            ->where('status', ScheduledGeofenceTrigger::STATUS_PENDING)
            ->update(['status' => ScheduledGeofenceTrigger::STATUS_CANCELLED]);
    }

    private function triggerServoForUser(User $user): void
    {
        $esp = $user->devices()->where('type', DeviceType::Esp8266->value)->first();

        if ($esp) {
            $this->device->triggerServo($esp);
        }
    }
}
```

**Controller becomes** thin — constructor-inject the service, replace
inline domain work with delegations:

```php
class GeoFenceController extends Controller
{
    public function __construct(private GeoFenceService $service) {}

    public function store(StoreGeoFenceRequest $request): JsonResponse
    {
        if ($request->user()->geofence) {
            return response()->json(['message' => 'Geofence already exists.'], 409);
        }

        $geofence = $this->service->createFor($request->user(), $request->validated());

        return GeoFenceResource::make($geofence)->response()->setStatusCode(201);
    }

    public function update(UpdateGeoFenceRequest $request, GeoFence $geoFence): GeoFenceResource
    {
        return GeoFenceResource::make($this->service->update($geoFence, $request->validated()));
    }

    public function destroy(Request $request, GeoFence $geoFence): JsonResponse
    {
        $this->authorize('delete', $geoFence);
        $this->service->delete($geoFence);

        return response()->json(['message' => 'Geofence deleted.']);
    }

    public function toggle(Request $request, GeoFence $geoFence): JsonResponse
    {
        $this->authorize('update', $geoFence);
        $this->service->toggle($geoFence);

        return response()->json(['is_active' => $geoFence->fresh()->is_active]);
    }

    public function check(CheckGeoFenceRequest $request, GeoFence $geoFence): JsonResponse
    {
        $validated = $request->validated();

        return response()->json(
            $this->service->check($request->user(), $geoFence, $validated['lat'], $validated['lng']),
        );
    }

    public function estimate(EstimateGeoFenceRequest $request, GeoFence $geoFence): JsonResponse
    {
        $validated = $request->validated();

        return response()->json(
            $this->service->estimate($geoFence, $validated['lat'], $validated['lng']),
        );
    }

    public function scheduleTrigger(ScheduleTriggerRequest $request, GeoFence $geoFence): JsonResponse
    {
        $validated = $request->validated();

        $trigger = $this->service->scheduleTrigger(
            $geoFence,
            $validated['minutes'],
            $validated['origin_lat'],
            $validated['origin_lng'],
        );

        return response()->json([
            'scheduled_trigger_id' => $trigger->id,
            'scheduled_at' => $trigger->scheduled_at->toIso8601String(),
            'fence' => ['is_active' => true],
        ]);
    }

    public function cancelScheduledTrigger(Request $request, GeoFence $geoFence): JsonResponse
    {
        $this->authorize('update', $geoFence);
        $this->service->cancelScheduledTrigger($geoFence);

        return response()->json(['fence' => ['is_active' => $geoFence->fresh()->is_active]]);
    }
}
```

**`TriggerScheduledGeofenceJob` also gets pulled into the pattern** —
its inline `where('type', DeviceType::Esp8266->value)` + `triggerServo`
becomes a `GeoFenceService` call, so both callers (the controller's
live `check` and the job's scheduled fire) share exactly one servo
path. If servo triggering ever grows a retry, a per-device state
check, or a metric — one place to change.

## Design decisions to defend

1. **Service takes primitives (float, int) rather than the FormRequest.**
   Services should not depend on the HTTP layer. The controller
   unwraps `$request->validated()` and passes primitives.
2. **No DTOs for return values.** For two-to-four field returns, an
   `array` shape documented via a PHPDoc `@return array{...}` is
   enough. DTOs are useful when a value crosses many layers or grows
   invariants — none of the current returns do.
3. **`createFor(User $user, array $data)` accepts the User rather
   than deriving from a passed geofence.** New geofence, no model to
   derive from — the user is the aggregate root.
4. **`check()` and `scheduleTrigger()` take User via the User
   relation, not from the fence's `user` relation.** Explicit
   parameters read cleaner than `$geoFence->user->devices()->...`
   inside the service.
5. **Service takes `DeviceInterface` via constructor.** Same
   `singleton` binding as `GarageController` uses today — no double-
   binding, no new provider registration.
6. **`private function triggerServoForUser(User $user)`** groups the
   type-filter + trigger into a private helper that both `check()`
   and future callers use. Keeps public methods focused.
7. **DB::transaction stays in `scheduleTrigger`.** Row-lock semantics
   are load-bearing; moving them out of the operation would risk a
   future maintainer removing the lock. Documented via comment.

## Behavior guarantees

- **No wire-format change.** All 8 endpoint response shapes are
  preserved byte-for-byte.
- **Job dispatch ordering preserved.** Job dispatches AFTER the
  transaction commits, same as today.
- **`live_check_armed` clearing on servo fire is preserved.**
- **`scheduleTrigger` row lock is preserved.**

## Acceptance criteria

- [ ] `app/Services/GeoFenceService.php` exists with the 7 methods
      declared in the Solution section.
- [ ] `GeoFenceController` no longer imports `DeviceInterface`,
      `App\Jobs\TriggerScheduledGeofenceJob`,
      `App\Models\ScheduledGeofenceTrigger`, `Illuminate\Support\Facades\DB`,
      or `App\Enums\DeviceType` — those move to the service.
- [ ] `GeoFenceController` has a `__construct(private GeoFenceService $service)`.
- [ ] `check()` no longer takes a `DeviceInterface $device` parameter
      — the service owns that dep.
- [ ] `TriggerScheduledGeofenceJob::handle()` uses the service for
      its servo-triggering path (or is documented as reverted in
      Notes if there's a reason not to). Preferred: refactor.
- [ ] `composer test` reports 145/145 unchanged.
- [ ] No test asserts against the removed imports (grep-verify).

## Out of scope

- **Splitting `GeoFenceService` into multiple services.**
  `GeoFenceQueryService` + `GeoFenceMutationService` +
  `GeoFenceScheduleService` is a further split some teams do; one
  service today, split when the file exceeds ~300 lines.
- **DTOs for `CheckResult` / `EstimateResult`.** Discussed above —
  array shapes with PHPDoc are the current trade-off.
- **Unit tests for the service.** The 15+ feature tests around
  geofence behavior transitively exercise every service method.
  Direct unit tests are worthwhile follow-up but not blocking.
- **Applying the same pattern to Device, Stream, or DeviceClaim.**
  WOLF-113 and WOLF-114 cover Stream and DeviceClaim; Device stays
  as-is (mostly Inertia pages, less domain logic to extract).

## Effort breakdown

| Step | Estimate |
|---|---|
| Create `GeoFenceService.php` | 25 min |
| Refactor `GeoFenceController` (constructor + 8 methods) | 25 min |
| Refactor `TriggerScheduledGeofenceJob` to use the service | 15 min |
| Run test suite; fix any wire-format drift | 15 min |
| Grep-verify controller no longer imports domain classes | 10 min |

## Sequencing

First ticket in Wave 3. Depends on WOLF-109 / WOLF-110 / WOLF-111 all
being merged. Independent from WOLF-113 and WOLF-114 (different
domains).

## Notes

- **Rollback risk:** low. Behavior guarantees documented above are
  covered by existing tests. If a test regresses, the service
  contract is wrong — fix the service, not the test.
- **Interview readiness:** the service is the single sentence a
  senior interviewer expects to hear: *"The controller extracts
  input, delegates to `GeoFenceService`, and wraps the return in a
  Resource. The service owns the domain — distance math, device
  triggering, transactional trigger scheduling."*
