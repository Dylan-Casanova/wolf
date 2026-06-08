<?php

namespace App\Contracts;

use App\Models\Device;

interface DeviceInterface
{
    /**
     * Send a start_stream command to a device.
     */
    public function startStream(Device $device, int $streamId): bool;

    /**
     * Send a stop_stream command to a device.
     */
    public function stopStream(Device $device): bool;

    /**
     * Check if the MQTT broker is reachable.
     */
    public function ping(): bool;

    /**
     * Send a trigger_servo command to a device.
     */
    public function triggerServo(Device $device, int $angle = 130): bool;
}
