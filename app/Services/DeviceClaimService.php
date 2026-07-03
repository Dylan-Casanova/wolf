<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\DeviceClaimResult;
use App\Models\Device;
use App\Models\User;

class DeviceClaimService
{
    public function claim(User $user, string $deviceId): DeviceClaimOutcome
    {
        $device = Device::where('device_id', $deviceId)->first();

        if (! $device) {
            return new DeviceClaimOutcome(DeviceClaimResult::DeviceNotFound);
        }

        if ($device->user_id === $user->id) {
            return new DeviceClaimOutcome(DeviceClaimResult::AlreadyOwned, $device);
        }

        if ($device->user_id !== null) {
            return new DeviceClaimOutcome(DeviceClaimResult::AlreadyClaimed, $device);
        }

        $device->update(['user_id' => $user->id]);

        return new DeviceClaimOutcome(DeviceClaimResult::Claimed, $device->fresh());
    }
}
