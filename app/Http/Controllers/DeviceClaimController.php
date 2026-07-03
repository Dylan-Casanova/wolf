<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Device;
use Illuminate\Http\Request;
use Inertia\Inertia;

class DeviceClaimController extends Controller
{
    public function create()
    {
        return Inertia::render('Devices/Claim');
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'device_id' => ['required', 'string'],
        ]);

        $device = Device::where('device_id', $validated['device_id'])->first();

        if (! $device) {
            return back()->withErrors(['device_id' => 'Device not found.']);
        }

        if ($device->user_id === $request->user()->id) {
            return back()->withErrors(['device_id' => 'You already own this device.']);
        }

        if ($device->user_id !== null) {
            return back()->withErrors(['device_id' => 'Device is already claimed.']);
        }

        $device->update(['user_id' => $request->user()->id]);

        return redirect()->route('dashboard');
    }
}
