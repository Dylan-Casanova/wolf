<?php

use App\Http\Controllers\Api\DeviceProvisionController;
use App\Http\Controllers\DeviceCaptureController;
use App\Http\Controllers\GeoFenceController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Authenticated routes (Sanctum — cookie for web, token for React Native)
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', fn(Request $request) => $request->user());

    // Device captures
    Route::post('/device/capture', [DeviceCaptureController::class, 'store'])->middleware('throttle:device-capture');
    Route::get('/device/captures', [DeviceCaptureController::class, 'index']);

    // Geo-fences (V1.1 — stubs returning 501)
    Route::apiResource('geo-fences', GeoFenceController::class)->only(['index', 'store', 'update', 'destroy']);
    Route::post('geo-fences/{geoFence}/check', [GeoFenceController::class, 'check']);
});

// Device provisioning (called by ESP32 during setup — no auth required)
Route::get('/device/{deviceId}/provision', DeviceProvisionController::class)
    ->name('device.provision');

// ESP32 callback — called by the board after capturing media.
// The board authenticates using the capture_id received via MQTT.
Route::post('/device/captures/{capture}/upload', [DeviceCaptureController::class, 'upload'])
    ->name('device.captures.upload');
