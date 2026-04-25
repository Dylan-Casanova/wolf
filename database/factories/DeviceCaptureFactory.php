<?php

namespace Database\Factories;

use App\Models\Device;
use App\Models\DeviceCapture;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DeviceCapture>
 */
class DeviceCaptureFactory extends Factory
{
    protected $model = DeviceCapture::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'device_id' => Device::factory(),
            'geo_fence_id' => null,
            'trigger_source' => 'manual',
            'media_type' => 'image',
            'media_url' => null,
            'media_path' => null,
            'status' => 'success',
            'error_message' => null,
            'device_meta' => null,
        ];
    }
}
