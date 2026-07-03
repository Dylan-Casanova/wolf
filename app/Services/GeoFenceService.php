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
            // Acquire a row-level lock on this fence; held until commit/rollback.
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
        // a job queued referencing a row that doesn't exist.
        TriggerScheduledGeofenceJob::dispatch($trigger->id)->delay($trigger->scheduled_at);

        return $trigger;
    }

    public function cancelScheduledTrigger(GeoFence $geoFence): void
    {
        ScheduledGeofenceTrigger::where('geo_fence_id', $geoFence->id)
            ->where('status', ScheduledGeofenceTrigger::STATUS_PENDING)
            ->update(['status' => ScheduledGeofenceTrigger::STATUS_CANCELLED]);
    }

    public function triggerServoForUser(User $user): void
    {
        $esp = $user->devices()->where('type', DeviceType::Esp8266->value)->first();

        if ($esp) {
            $this->device->triggerServo($esp);
        }
    }
}
