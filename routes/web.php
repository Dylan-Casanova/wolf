<?php

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
        $device = auth()->user()->devices()->first();

        return Inertia::render('Dashboard', [
            'deviceId' => $device?->id,
        ]);
    })->name('dashboard');

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
