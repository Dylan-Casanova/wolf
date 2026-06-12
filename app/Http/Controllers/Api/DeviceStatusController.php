<?php

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
            'mqtt_host' => parse_url(config('app.url'), PHP_URL_HOST),
            'mqtt_port' => 1883,
        ]);
    }
}
