<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class GeoFencePageController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $geofence = $request->user()->geofence?->load('pendingScheduledTrigger');

        return Inertia::render('Geofence/Index', [
            'geofence' => $geofence,
            'server_now' => now()->toIso8601String(),
        ]);
    }
}
