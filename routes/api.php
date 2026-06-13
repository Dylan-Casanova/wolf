<?php

use App\Http\Controllers\Api\DeviceRegisterController;
use App\Http\Controllers\Api\DeviceStatusController;
use App\Http\Controllers\StreamFeedController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Authenticated routes (Sanctum — cookie for web, token for React Native)
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', fn (Request $request) => $request->user());

    // Geo-fences (kept for future React Native / API token auth)
    // Web-session versions are in routes/web.php
});

// Device self-registration (called by ESP on first boot — no auth, rate limited)
Route::post('/device/register', DeviceRegisterController::class)
    ->middleware('throttle:10,1')
    ->name('device.register');

// Device status polling (called by ESP every 30s — authenticated by device token)
Route::get('/device/{deviceId}/status', DeviceStatusController::class)
    ->name('device.status');

// ESP32 stream feed — authenticated by device token
Route::post('/device/stream/{stream}/feed', [StreamFeedController::class, 'feed']);
