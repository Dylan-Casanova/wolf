<?php

namespace App\Services\Device;

use App\Contracts\DeviceInterface;
use App\Models\Device;
use Illuminate\Support\Facades\Log;
use PhpMqtt\Client\ConnectionSettings;
use PhpMqtt\Client\MqttClient;

class Esp32MqttDevice implements DeviceInterface
{
    public function __construct(
        private string $host,
        private int $port,
        private ?string $username,
        private ?string $password,
    ) {}

    public function startStream(Device $device, int $streamId): bool
    {
        return $this->publish($device->commandTopic(), json_encode([
            'action' => 'start_stream',
            'stream_id' => $streamId,
        ]));
    }

    public function stopStream(Device $device): bool
    {
        return $this->publish($device->commandTopic(), json_encode([
            'action' => 'stop_stream',
        ]));
    }

    public function triggerServo(Device $device): bool
    {
        return $this->publish($device->commandTopic(), json_encode([
            'action' => 'trigger_servo',
        ]));
    }

    public function ping(): bool
    {
        try {
            $client = new MqttClient($this->host, $this->port, 'wolf-ping');
            $client->connect(null, true);
            $client->disconnect();

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function publish(string $topic, string $payload): bool
    {
        try {
            $client = new MqttClient($this->host, $this->port, 'wolf-server-'.uniqid());

            $connectionSettings = new ConnectionSettings;

            if ($this->username && $this->username !== 'null') {
                $connectionSettings = $connectionSettings
                    ->setUsername($this->username)
                    ->setPassword($this->password);
            }

            $client->connect($connectionSettings, true);
            $client->publish($topic, $payload, qualityOfService: 1);
            $client->disconnect();

            return true;
        } catch (\Throwable $e) {
            Log::error('MQTT publish failed', [
                'error' => $e->getMessage(),
                'topic' => $topic,
            ]);

            return false;
        }
    }
}
