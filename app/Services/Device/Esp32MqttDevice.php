<?php

namespace App\Services\Device;

use App\Contracts\DeviceInterface;
use App\Models\Device;
use Illuminate\Support\Facades\Log;

/**
 * ESP32-CAM device driver using MQTT for command dispatch.
 *
 * Flow:
 *   1. requestCapture() publishes to wolf/{device_id}/command
 *   2. The ESP32 subscribes to that topic, takes the photo, and POSTs the binary
 *      back to POST /api/device/captures/{id}/upload
 *   3. CaptureService finalises the record and broadcasts CaptureReady to the frontend
 *
 * Requires: php-mqtt/client  (composer require php-mqtt/client)
 */
class Esp32MqttDevice implements DeviceInterface
{
    public function __construct(
        private string  $host,
        private int     $port,
        private ?string $username,
        private ?string $password,
    ) {}

    public function requestCapture(Device $device, int $captureId): bool
    {
        try {
            $client = new \PhpMqtt\Client\MqttClient($this->host, $this->port, 'wolf-server-' . uniqid());

            $connectionSettings = (new \PhpMqtt\Client\ConnectionSettings)
                ->setUsername($this->username)
                ->setPassword($this->password);

            $client->connect($connectionSettings, cleanSession: true);

            $payload = json_encode([
                'action'     => 'capture',
                'capture_id' => $captureId,
                'type'       => 'image',
            ]);

            $client->publish($device->commandTopic(), $payload, qualityOfService: 1);
            $client->disconnect();

            return true;
        } catch (\Throwable $e) {
            Log::error('MQTT capture command failed', [
                'error'      => $e->getMessage(),
                'capture_id' => $captureId,
                'device_id'  => $device->device_id,
            ]);
            return false;
        }
    }

    public function ping(): bool
    {
        try {
            $client = new \PhpMqtt\Client\MqttClient($this->host, $this->port, 'wolf-ping');
            $client->connect(cleanSession: true);
            $client->disconnect();
            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
