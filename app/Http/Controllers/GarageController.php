<?php

namespace App\Http\Controllers;

use App\Contracts\DeviceInterface;
use Illuminate\Http\Request;

class GarageController extends Controller
{
    public function __construct(private DeviceInterface $device) {}

    public function trigger(Request $request)
    {
        $user = $request->user();
        $device = $user->devices()->first();

        if (! $device) {
            return response()->json(['message' => 'No device registered.'], 422);
        }

        $angle = $request->integer('angle', 130);
        $angle = max(0, min(180, $angle));

        $this->device->triggerServo($device, $angle);

        return response()->json(['message' => 'Command sent.']);
    }
}
