<?php

namespace App\Http\Controllers\Api;

use App\Enums\DeviceType;
use App\Http\Controllers\Controller;
use App\Models\Device;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\Rule;

class DeviceRegisterController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'device_id' => [
                'required',
                'string',
                'max:255',
                'regex:/^(ESP8266|ESP32)-\d{3,}$/i',
            ],
            'type' => ['required', Rule::in(DeviceType::values())],
            'name' => ['required', 'string', 'max:255'],
        ]);

        $existing = Device::where('device_id', $validated['device_id'])->first();

        if ($existing) {
            return response()->json([
                'device_id' => $existing->device_id,
                'token' => Crypt::decryptString($existing->token_encrypted),
            ]);
        }

        $device = Device::create([
            'name' => $validated['name'],
            'device_id' => $validated['device_id'],
            'type' => $validated['type'],
            'user_id' => null,
            'token_hash' => '',
        ]);

        $token = $device->generateToken();

        return response()->json([
            'device_id' => $device->device_id,
            'token' => $token,
        ], 201);
    }
}
