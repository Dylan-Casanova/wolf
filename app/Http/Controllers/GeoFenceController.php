<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Contracts\DeviceInterface;
use App\Enums\DeviceType;
use App\Http\Requests\GeoFence\CheckGeoFenceRequest;
use App\Http\Requests\GeoFence\EstimateGeoFenceRequest;
use App\Http\Requests\GeoFence\ScheduleTriggerRequest;
use App\Http\Requests\GeoFence\StoreGeoFenceRequest;
use App\Http\Requests\GeoFence\UpdateGeoFenceRequest;
use App\Jobs\TriggerScheduledGeofenceJob;
use App\Models\GeoFence;
use App\Models\ScheduledGeofenceTrigger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class GeoFenceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $geofence = $request->user()->geofence;

        return response()->json($geofence ? [$geofence] : []);
    }

    public function store(StoreGeoFenceRequest $request): JsonResponse
    {
        if ($request->user()->geofence) {
            return response()->json(['message' => 'Geofence already exists.'], 409);
        }

        $geofence = $request->user()->geofence()->create($request->validated());

        return response()->json($geofence, 201);
    }

    public function update(UpdateGeoFenceRequest $request, GeoFence $geoFence): JsonResponse
    {
        $geoFence->update($request->validated());

        return response()->json($geoFence);
    }

    public function destroy(Request $request, GeoFence $geoFence): JsonResponse
    {
        $this->authorize('delete', $geoFence);

        $geoFence->delete();

        return response()->json(['message' => 'Geofence deleted.']);
    }

    public function toggle(Request $request, GeoFence $geoFence): JsonResponse
    {
        $this->authorize('update', $geoFence);

        $geoFence->update(['live_check_armed' => ! $geoFence->live_check_armed]);

        return response()->json(['is_active' => $geoFence->fresh()->is_active]);
    }

    public function check(CheckGeoFenceRequest $request, GeoFence $geoFence, DeviceInterface $device): JsonResponse
    {
        $validated = $request->validated();

        $distance = $geoFence->distanceFromCenter($validated['lat'], $validated['lng']);
        $inside = $geoFence->live_check_armed && $geoFence->contains($validated['lat'], $validated['lng']);

        if ($inside) {
            $esp = $request->user()->devices()->where('type', DeviceType::Esp8266->value)->first();

            if ($esp) {
                $device->triggerServo($esp);
            }

            $geoFence->update(['live_check_armed' => false]);
        }

        return response()->json([
            'triggered' => $inside,
            'distance_meters' => round($distance),
        ]);
    }

    public function estimate(EstimateGeoFenceRequest $request, GeoFence $geoFence): JsonResponse
    {
        $validated = $request->validated();

        $distanceMeters = $geoFence->distanceFromCenter($validated['lat'], $validated['lng']);
        $distanceMiles = $distanceMeters / 1609.34;
        $speedMph = config('wolf.estimated_arrival_mph', 35);
        $estimatedMinutes = (int) max(1, round(($distanceMiles / $speedMph) * 60));

        return response()->json([
            'distance_miles' => round($distanceMiles, 1),
            'estimated_minutes' => $estimatedMinutes,
            'assumed_speed_mph' => $speedMph,
        ]);
    }

    public function scheduleTrigger(ScheduleTriggerRequest $request, GeoFence $geoFence): JsonResponse
    {
        $validated = $request->validated();

        // Serialize cancel + create per fence with a row lock so concurrent
        // requests can't create two pending triggers for the same fence.
        $trigger = DB::transaction(function () use ($geoFence, $validated) {
            // Acquire a row-level lock on this fence; held until commit/rollback.
            GeoFence::whereKey($geoFence->id)->lockForUpdate()->first();

            ScheduledGeofenceTrigger::where('geo_fence_id', $geoFence->id)
                ->where('status', ScheduledGeofenceTrigger::STATUS_PENDING)
                ->update(['status' => ScheduledGeofenceTrigger::STATUS_CANCELLED]);

            $distanceMeters = $geoFence->distanceFromCenter($validated['origin_lat'], $validated['origin_lng']);
            $scheduledAt = now()->addMinutes($validated['minutes']);

            $trigger = ScheduledGeofenceTrigger::create([
                'geo_fence_id' => $geoFence->id,
                'scheduled_at' => $scheduledAt,
                'origin_lat' => $validated['origin_lat'],
                'origin_lng' => $validated['origin_lng'],
                'origin_distance_meters' => $distanceMeters,
                'status' => ScheduledGeofenceTrigger::STATUS_PENDING,
            ]);

            // No fence write: the pending trigger row alone makes is_active derive true.
            return $trigger;
        });

        // Dispatch outside the transaction: if commit had rolled back, the job
        // would still be queued referencing a row that doesn't exist.
        TriggerScheduledGeofenceJob::dispatch($trigger->id)->delay($trigger->scheduled_at);

        return response()->json([
            'scheduled_trigger_id' => $trigger->id,
            'scheduled_at' => $trigger->scheduled_at->toIso8601String(),
            'fence' => ['is_active' => true],
        ]);
    }

    public function cancelScheduledTrigger(Request $request, GeoFence $geoFence): JsonResponse
    {
        $this->authorize('update', $geoFence);

        ScheduledGeofenceTrigger::where('geo_fence_id', $geoFence->id)
            ->where('status', ScheduledGeofenceTrigger::STATUS_PENDING)
            ->update(['status' => ScheduledGeofenceTrigger::STATUS_CANCELLED]);

        // No fence write: accessor derives is_active from live_check_armed OR pending trigger.
        // Cancelling the trigger alone is enough to flip the derived value off.
        return response()->json(['fence' => ['is_active' => $geoFence->fresh()->is_active]]);
    }
}
