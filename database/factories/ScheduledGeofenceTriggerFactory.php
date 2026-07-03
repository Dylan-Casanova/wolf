<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\GeoFence;
use App\Models\ScheduledGeofenceTrigger;
use Illuminate\Database\Eloquent\Factories\Factory;

class ScheduledGeofenceTriggerFactory extends Factory
{
    protected $model = ScheduledGeofenceTrigger::class;

    public function definition(): array
    {
        return [
            'geo_fence_id' => GeoFence::factory(),
            'scheduled_at' => now()->addMinutes(15),
            'status' => ScheduledGeofenceTrigger::STATUS_PENDING,
            'origin_lat' => 29.4250,
            'origin_lng' => -98.4915,
            'origin_distance_meters' => 8000.0,
        ];
    }

    public function cancelled(): static
    {
        return $this->state(fn () => ['status' => ScheduledGeofenceTrigger::STATUS_CANCELLED]);
    }

    public function fired(): static
    {
        return $this->state(fn () => ['status' => ScheduledGeofenceTrigger::STATUS_FIRED]);
    }
}
