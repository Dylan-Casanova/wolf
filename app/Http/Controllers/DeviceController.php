<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreDeviceRequest;
use App\Http\Requests\UpdateDeviceRequest;
use App\Models\Device;
use App\Models\User;
use Inertia\Inertia;

class DeviceController extends Controller
{
    public function index()
    {
        $devices = Device::with('user')->latest()->get();

        return Inertia::render('Devices/Index', [
            'devices' => $devices,
        ]);
    }

    public function create()
    {
        $availableUsers = User::whereDoesntHave('devices')
            ->select('id', 'name', 'email')
            ->orderBy('name')
            ->get();

        return Inertia::render('Devices/Create', [
            'users' => $availableUsers,
        ]);
    }

    public function store(StoreDeviceRequest $request)
    {
        $device = Device::create([
            'name' => $request->name,
            'device_id' => $request->device_id,
            'user_id' => $request->user_id,
            'type' => $request->type ?? 'esp32-cam',
            'token_hash' => '',
        ]);

        $token = $device->generateToken();

        return redirect()->route('devices.index')->with('device_token', $token);
    }

    public function edit(Device $device)
    {
        $device->load('user');

        $availableUsers = User::where(function ($query) use ($device) {
            $query->whereDoesntHave('devices')
                ->orWhere('id', $device->user_id);
        })
            ->select('id', 'name', 'email')
            ->orderBy('name')
            ->get();

        return Inertia::render('Devices/Edit', [
            'device' => $device,
            'users' => $availableUsers,
        ]);
    }

    public function update(UpdateDeviceRequest $request, Device $device)
    {
        $device->update([
            'name' => $request->name,
            'device_id' => $request->device_id,
            'user_id' => $request->user_id,
            'type' => $request->type ?? $device->type,
        ]);

        return redirect()->route('devices.index');
    }

    public function destroy(Device $device)
    {
        $device->delete();

        return redirect()->route('devices.index');
    }

    public function regenerateToken(Device $device)
    {
        $token = $device->generateToken();

        return back()->with('device_token', $token);
    }
}
