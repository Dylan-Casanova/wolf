<?php

namespace App\Console\Commands;

use App\Events\DeviceStatusChanged;
use App\Models\Device;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use PhpMqtt\Client\ConnectionSettings;
use PhpMqtt\Client\MqttClient;

class MqttListenCommand extends Command
{
    protected $signature = 'mqtt:listen';

    protected $description = 'Subscribe to MQTT device status and heartbeat topics';

    public function handle(): int
    {
        $host = config('device.mqtt.host');
        $port = config('device.mqtt.port');
        $username = config('device.mqtt.username');
        $password = config('device.mqtt.password');

        $this->info("Connecting to MQTT broker at {$host}:{$port}...");

        $client = new MqttClient($host, $port, 'wolf-listener-'.uniqid());

        $connectionSettings = new ConnectionSettings;

        if ($username && $username !== 'null') {
            $connectionSettings = $connectionSettings
                ->setUsername($username)
                ->setPassword($password);
        }

        $client->connect($connectionSettings, true);

        $client->subscribe('wolf/+/status', function (string $topic, string $message) {
            $this->handleMessage($topic, $message);
        }, qualityOfService: 1);

        $client->subscribe('wolf/+/heartbeat', function (string $topic, string $message) {
            $this->handleMessage($topic, $message);
        }, qualityOfService: 1);

        $this->info('Subscribed to wolf/+/status and wolf/+/heartbeat. Listening...');

        $client->loop(true);

        return self::SUCCESS;
    }

    public function handleMessage(string $topic, string $message): void
    {
        $parts = explode('/', $topic);

        if (count($parts) < 3) {
            return;
        }

        $deviceId = $parts[1];
        $type = $parts[2];

        $device = Device::where('device_id', $deviceId)->first();

        if (! $device) {
            Log::warning("MQTT message for unknown device: {$deviceId}");

            return;
        }

        if ($type === 'status') {
            $message = trim($message);

            if ($message === 'online') {
                $device->markOnline();
            } elseif ($message === 'offline') {
                $device->markOffline();
            }
        } elseif ($type === 'heartbeat') {
            $meta = json_decode($message, true) ?? [];
            $device->markOnline($meta);
        }

        DeviceStatusChanged::dispatch($device->fresh());
    }
}
