<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Contracts\DeviceInterface;
use Illuminate\Http\Request;

class GarageController extends Controller
{
    public function __construct(private DeviceInterface $device) {}

    public function trigger(Request $request)
    {
        $user = $request->user();

        $request->validate([
            'device_id' => ['sometimes', 'integer'],
        ]);

        $device = $request->device_id
            ? $user->devices()->where('id', $request->device_id)->first()
            : $user->devices()->first();

        if (! $device) {
            return response()->json(['message' => 'No device registered.'], 422);
        }

        $this->device->triggerServo($device);

        return response()->json(['message' => 'Command sent.']);
    }
}
