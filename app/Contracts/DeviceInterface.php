<?php

namespace App\Contracts;

use App\Models\Device;

interface DeviceInterface
{
    /**
     * Send a capture command to a specific device.
     * Returns true if the command was dispatched successfully.
     * The actual media arrives asynchronously via the upload callback.
     */
    public function requestCapture(Device $device, int $captureId): bool;

    /**
     * Check if a specific device's MQTT broker is reachable.
     */
    public function ping(): bool;
}
