<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Device;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DeviceStatusController extends Controller
{
    public function __invoke(Request $request, string $deviceId): JsonResponse
    {
        $device = Device::where('device_id', $deviceId)->first();

        if (! $device) {
            return response()->json(['error' => 'Device not found'], 404);
        }

        $token = $request->bearerToken();

        if (! $token || ! $device->verifyToken($token)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $device->update(['last_seen_at' => now()]);

        if ($device->user_id === null) {
            return response()->json(['paired' => false]);
        }

        return response()->json([
            'paired' => true,
            'mqtt_host' => $this->isLocalDev() ? config('device.localFirmwareIp') : parse_url(config('app.url'), PHP_URL_HOST),
            'mqtt_port' => $this->isLocalDev() ? 1883 : (int) config('device.mqtt.port', 1883),
        ]);
    }

    private function isLocalDev(): bool
    {
        return config('app.env') === 'local';
    }
}
