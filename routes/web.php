<?php

use App\Http\Controllers\DeviceClaimController;
use App\Http\Controllers\DeviceController;
use App\Http\Controllers\GarageController;
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
        $devices = auth()->user()->devices()->get()->map(fn ($device) => [
            'id' => $device->id,
            'name' => $device->name,
            'device_id' => $device->device_id,
            'type' => $device->type->value,
            'is_online' => $device->is_online,
        ]);

        return Inertia::render('Dashboard', [
            'devices' => $devices,
        ]);
    })->name('dashboard');

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

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
