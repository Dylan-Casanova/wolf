<?php

namespace Database\Factories;

use App\Models\GeoFence;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class GeoFenceFactory extends Factory
{
    protected $model = GeoFence::class;

    public function definition(): array
    {
        $lat = $this->faker->latitude(29.0, 30.0);
        $lng = $this->faker->longitude(-98.5, -97.5);

        return [
            'user_id' => User::factory(),
            'north_lat' => $lat + 0.002,
            'south_lat' => $lat - 0.002,
            'east_lng' => $lng + 0.003,
            'west_lng' => $lng - 0.003,
            'is_active' => false,
        ];
    }

    public function active(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => true,
        ]);
    }
}
