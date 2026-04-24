<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Device;
use Illuminate\Support\Facades\Crypt;

class DeviceProvisionController extends Controller
{
    /**
     * Return configuration for an ESP32 device.
     *
     * Called by the ESP32 firmware after connecting to WiFi during first-time setup.
     * The device sends its device_id and receives back everything it needs:
     * server URL, MQTT connection details, and its auth token.
     *
     * GET /api/device/{deviceId}/provision
     */
    public function __invoke(string $deviceId)
    {
        $device = Device::where('device_id', $deviceId)->first();

        if (!$device) {
            return response()->json([
                'error' => 'Device not found',
            ], 404);
        }

        if (!$device->token_encrypted) {
            return response()->json([
                'error' => 'Device token not available. Regenerate token in admin.',
            ], 422);
        }

        return response()->json([
            'device_id'    => $device->device_id,
            'device_token' => Crypt::decryptString($device->token_encrypted),
            'server_url'   => "http://192.168.1.97:8000",
            'mqtt_host'    => "192.168.1.97",
            'mqtt_port'    => "1883",
        ]);
    }
}
