<?php

namespace App\Http\Controllers;

use App\Contracts\DeviceInterface;
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

    public function store(Request $request): JsonResponse
    {
        if ($request->user()->geofence) {
            return response()->json(['message' => 'Geofence already exists.'], 409);
        }

        $validated = $request->validate([
            'north_lat' => ['required', 'numeric', 'between:-90,90'],
            'south_lat' => ['required', 'numeric', 'between:-90,90'],
            'east_lng' => ['required', 'numeric', 'between:-180,180'],
            'west_lng' => ['required', 'numeric', 'between:-180,180'],
            'address_lat' => ['nullable', 'numeric', 'between:-90,90'],
            'address_lng' => ['nullable', 'numeric', 'between:-180,180'],
        ]);

        $geofence = $request->user()->geofence()->create($validated);

        return response()->json($geofence, 201);
    }

    public function update(Request $request, GeoFence $geoFence): JsonResponse
    {
        if ($geoFence->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $validated = $request->validate([
            'north_lat' => ['required', 'numeric', 'between:-90,90'],
            'south_lat' => ['required', 'numeric', 'between:-90,90'],
            'east_lng' => ['required', 'numeric', 'between:-180,180'],
            'west_lng' => ['required', 'numeric', 'between:-180,180'],
            'address_lat' => ['nullable', 'numeric', 'between:-90,90'],
            'address_lng' => ['nullable', 'numeric', 'between:-180,180'],
        ]);

        $geoFence->update($validated);

        return response()->json($geoFence);
    }

    public function destroy(Request $request, GeoFence $geoFence): JsonResponse
    {
        if ($geoFence->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $geoFence->delete();

        return response()->json(['message' => 'Geofence deleted.']);
    }

    public function toggle(Request $request, GeoFence $geoFence): JsonResponse
    {
        if ($geoFence->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $geoFence->update(['is_active' => ! $geoFence->is_active]);

        return response()->json(['is_active' => $geoFence->fresh()->is_active]);
    }

    public function check(Request $request, GeoFence $geoFence, DeviceInterface $device): JsonResponse
    {
        if ($geoFence->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $validated = $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
        ]);

        $distance = $geoFence->distanceFromCenter($validated['lat'], $validated['lng']);
        $inside = $geoFence->is_active && $geoFence->contains($validated['lat'], $validated['lng']);

        if ($inside) {
            $esp = $request->user()->devices()->where('type', 'esp8266')->first();

            if ($esp) {
                $device->triggerServo($esp);
            }

            $geoFence->update(['is_active' => false]);
        }

        return response()->json([
            'triggered' => $inside,
            'distance_meters' => round($distance),
        ]);
    }

    public function estimate(Request $request, GeoFence $geoFence): JsonResponse
    {
        if ($geoFence->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $validated = $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
        ]);

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

    public function scheduleTrigger(Request $request, GeoFence $geoFence): JsonResponse
    {
        if ($geoFence->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $validated = $request->validate([
            'minutes' => ['required', 'integer', 'between:1,180'],
            'origin_lat' => ['required', 'numeric', 'between:-90,90'],
            'origin_lng' => ['required', 'numeric', 'between:-180,180'],
        ]);

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

            $geoFence->update(['is_active' => true]);

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
        if ($geoFence->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        ScheduledGeofenceTrigger::where('geo_fence_id', $geoFence->id)
            ->where('status', ScheduledGeofenceTrigger::STATUS_PENDING)
            ->update(['status' => ScheduledGeofenceTrigger::STATUS_CANCELLED]);

        $geoFence->update(['is_active' => false]);

        return response()->json(['fence' => ['is_active' => false]]);
    }
}
