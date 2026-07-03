<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $user = $request->user();

        $devices = $user->devices()->get()->map(fn ($device) => [
            'id' => $device->id,
            'name' => $device->name,
            'device_id' => $device->device_id,
            'type' => $device->type->value,
            'is_online' => $device->is_online,
        ]);

        return Inertia::render('Dashboard', [
            'devices' => $devices,
            'geofence' => $user->geofence?->load('pendingScheduledTrigger'),
            'server_now' => now()->toIso8601String(),
        ]);
    }
}
