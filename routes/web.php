<?php

declare(strict_types=1);

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DeviceClaimController;
use App\Http\Controllers\DeviceController;
use App\Http\Controllers\GarageController;
use App\Http\Controllers\GeoFenceController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\StreamController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', fn () => Inertia::render('Welcome'))->name('home');

Route::get('/health', HealthController::class)->name('health');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/dashboard', DashboardController::class)->name('dashboard');

    Route::get('/geofence', function () {
        $geofence = auth()->user()->geofence?->load('pendingScheduledTrigger');

        return Inertia::render('Geofence/Index', [
            'geofence' => $geofence,
            'server_now' => now()->toIso8601String(),
        ]);
    })->name('geofence');

    // Device claiming (POST only — UI lives in the Navbar1 modal)
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
