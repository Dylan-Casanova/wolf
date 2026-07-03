<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Device;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class StreamFactory extends Factory
{
    public function definition(): array
    {
        return [
            'device_id' => Device::factory(),
            'user_id' => User::factory(),
            'status' => 'pending',
        ];
    }
}
