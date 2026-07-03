<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Resources\DeviceResource;
use App\Http\Resources\GeoFenceResource;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $user = $request->user();

        $geofence = $user->geofence?->load('pendingScheduledTrigger');

        return Inertia::render('Dashboard', [
            'devices' => DeviceResource::collection($user->devices()->get())->resolve($request),
            'geofence' => $geofence ? GeoFenceResource::make($geofence)->resolve($request) : null,
            'server_now' => now()->toIso8601String(),
        ]);
    }
}
