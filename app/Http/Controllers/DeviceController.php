<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\DeviceType;
use App\Http\Requests\StoreDeviceRequest;
use App\Http\Requests\UpdateDeviceRequest;
use App\Http\Resources\DeviceResource;
use App\Models\Device;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;

class DeviceController extends Controller
{
    public function index(Request $request)
    {
        $devices = Device::with('user')->latest()->get();

        return Inertia::render('Devices/Index', [
            'devices' => DeviceResource::collection($devices)->resolve($request),
        ]);
    }

    public function create()
    {
        $availableUsers = User::select('id', 'name', 'email')
            ->orderBy('name')
            ->get();

        return Inertia::render('Devices/Create', [
            'users' => $availableUsers,
            'deviceTypes' => DeviceType::options(),
        ]);
    }

    public function store(StoreDeviceRequest $request)
    {
        $device = Device::create([
            'name' => $request->name,
            'device_id' => $request->device_id,
            'user_id' => $request->user_id ?: null,
            'type' => $request->type,
            'token_hash' => '',
        ]);

        $token = $device->generateToken();

        return redirect()->route('devices.index')->with('device_token', $token);
    }

    public function edit(Device $device)
    {
        $device->load('user');

        $availableUsers = User::select('id', 'name', 'email')
            ->orderBy('name')
            ->get();

        return Inertia::render('Devices/Edit', [
            'device' => $device,
            'users' => $availableUsers,
            'deviceTypes' => DeviceType::options(),
        ]);
    }

    public function update(UpdateDeviceRequest $request, Device $device)
    {
        $device->update([
            'name' => $request->name,
            'device_id' => $request->device_id,
            'user_id' => $request->user_id ?: null,
            'type' => $request->type,
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
