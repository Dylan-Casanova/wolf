<?php

namespace App\Contracts;

interface DeviceInterface
{
    /**
     * Send a capture command to the device.
     * Returns true if the command was dispatched successfully.
     * The actual media arrives asynchronously via the upload callback.
     */
    public function requestCapture(int $captureId): bool;

    /**
     * Check if the device is reachable / online.
     */
    public function ping(): bool;
}
