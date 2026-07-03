<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\DeviceClaimResult;
use App\Models\Device;
use App\Models\User;

class DeviceClaimService
{
    public function claim(User $user, string $deviceId): DeviceClaimResult
    {
        $device = Device::where('device_id', $deviceId)->first();

        if (! $device) {
            return DeviceClaimResult::DeviceNotFound;
        }

        if ($device->user_id === $user->id) {
            return DeviceClaimResult::AlreadyOwned;
        }

        if ($device->user_id !== null) {
            return DeviceClaimResult::AlreadyClaimed;
        }

        $device->update(['user_id' => $user->id]);

        return DeviceClaimResult::Claimed;
    }
}
