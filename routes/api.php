<?php

use App\Http\Controllers\Api\DeviceProvisionController;
use App\Http\Controllers\GeoFenceController;
use App\Http\Controllers\StreamFeedController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Authenticated routes (Sanctum — cookie for web, token for React Native)
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', fn (Request $request) => $request->user());

    // Geo-fences (V1.1 — stubs returning 501)
    Route::apiResource('geo-fences', GeoFenceController::class)->only(['index', 'store', 'update', 'destroy']);
    Route::post('geo-fences/{geoFence}/check', [GeoFenceController::class, 'check']);
});

// Device provisioning (called by ESP32 during setup — no auth required)
Route::get('/device/{deviceId}/provision', DeviceProvisionController::class)
    ->name('device.provision');

// ESP32 stream feed — authenticated by device token
Route::post('/device/stream/{stream}/feed', [StreamFeedController::class, 'feed']);
