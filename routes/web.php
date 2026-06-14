<?php

use App\Http\Controllers\DeviceClaimController;
use App\Http\Controllers\DeviceController;
use App\Http\Controllers\GarageController;
use App\Http\Controllers\GeoFenceController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\StreamController;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Welcome', [
        'laravelVersion' => Application::VERSION,
        'phpVersion' => PHP_VERSION,
    ]);
});

Route::get('/health', HealthController::class)->name('health');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/dashboard', function () {
        $user = auth()->user();
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
    })->name('dashboard');

    Route::get('/geofence', function () {
        $geofence = auth()->user()->geofence?->load('pendingScheduledTrigger');

        return Inertia::render('Geofence/Index', [
            'geofence' => $geofence,
            'server_now' => now()->toIso8601String(),
        ]);
    })->name('geofence');

    // Device claiming (any logged-in user)
    Route::get('/devices/claim', [DeviceClaimController::class, 'create'])->name('devices.claim');
    Route::post('/devices/claim', [DeviceClaimController::class, 'store']);

    // Admin-only device management
    Route::middleware('admin')->group(function () {
        Route::resource('devices', DeviceController::class)->except(['show']);
        Route::post('/devices/{device}/regenerate-token', [DeviceController::class, 'regenerateToken'])
            ->name('devices.regenerate-token');
    });
});

Route::middleware('auth')->group(function () {
    // Streaming
    Route::post('/stream/start', [StreamController::class, 'start']);
    Route::post('/stream/{stream}/stop', [StreamController::class, 'stop']);

    // Garage
    Route::post('/garage/trigger', [GarageController::class, 'trigger']);

    // Geofence API (session-authenticated)
    Route::apiResource('geo-fences', GeoFenceController::class)->only(['index', 'store', 'update', 'destroy']);
    Route::post('geo-fences/{geoFence}/check', [GeoFenceController::class, 'check']);
    Route::post('geo-fences/{geoFence}/toggle', [GeoFenceController::class, 'toggle']);
    Route::post('geo-fences/{geoFence}/estimate', [GeoFenceController::class, 'estimate']);
    Route::post('geo-fences/{geoFence}/schedule-trigger', [GeoFenceController::class, 'scheduleTrigger']);
    Route::delete('geo-fences/{geoFence}/scheduled-trigger', [GeoFenceController::class, 'cancelScheduledTrigger']);

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
