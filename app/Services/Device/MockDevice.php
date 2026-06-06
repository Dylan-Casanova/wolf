<?php

namespace App\Services\Device;

use App\Contracts\DeviceInterface;
use App\Models\Device;

class MockDevice implements DeviceInterface
{
    public function startStream(Device $device, int $streamId): bool
    {
        return true;
    }

    public function stopStream(Device $device): bool
    {
        return true;
    }

    public function ping(): bool
    {
        return true;
    }
}
