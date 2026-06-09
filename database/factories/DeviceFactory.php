<?php

namespace Database\Factories;

use App\Enums\DeviceType;
use App\Models\Device;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends Factory<Device>
 */
class DeviceFactory extends Factory
{
    protected $model = Device::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->words(2, true).' cam',
            'device_id' => 'esp32-'.fake()->unique()->numerify('###'),
            'token_hash' => Hash::make('test-device-token'),
            'type' => DeviceType::Esp32Cam->value,
            'is_online' => false,
            'last_seen_at' => null,
            'meta' => null,
        ];
    }

    public function esp8266(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => DeviceType::Esp8266->value,
        ]);
    }

    public function online(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_online' => true,
            'last_seen_at' => now(),
        ]);
    }
}
