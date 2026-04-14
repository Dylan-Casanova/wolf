<?php

namespace App\Services\Device;

use App\Contracts\DeviceInterface;
use App\Models\Device;

/**
 * Mock device for local development and testing.
 * Simulates a successful capture command dispatch.
 * The CaptureService handles the fake "upload" response in mock mode.
 */
class MockDevice implements DeviceInterface
{
    public function requestCapture(Device $device, int $captureId): bool
    {
        return true;
    }

    public function ping(): bool
    {
        return true;
    }
}
