<?php

declare(strict_types=1);

use App\Http\Controllers\Api\DeviceRegisterController;
use App\Http\Controllers\Api\DeviceStatusController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\DeviceClaimController as ApiDeviceClaimController;
use App\Http\Controllers\Api\V1\DeviceController as ApiDeviceController;
use App\Http\Controllers\Api\V1\EmailVerificationController;
use App\Http\Controllers\GarageController;
use App\Http\Controllers\GeoFenceController;
use App\Http\Controllers\StreamFeedController;
use Illuminate\Support\Facades\Route;

// /api/v1 — Sanctum token-authenticated mobile API
Route::prefix('v1')->group(function () {
    // Public auth
    Route::post('/auth/login', [AuthController::class, 'login']);
    Route::post('/auth/register', [AuthController::class, 'register']);

    // Token-authenticated
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::get('/auth/user', [AuthController::class, 'user']);
        Route::post('/auth/email/verification-notification', [EmailVerificationController::class, 'send'])
            ->middleware('throttle:6,1')
            ->name('api.verification.send');

        // Geofence (reuses the same controllers used by the web — both return JSON)
        Route::apiResource('geo-fences', GeoFenceController::class)
            ->only(['index', 'store', 'update', 'destroy']);
        Route::post('geo-fences/{geoFence}/check', [GeoFenceController::class, 'check']);
        Route::post('geo-fences/{geoFence}/toggle', [GeoFenceController::class, 'toggle']);

        // Garage trigger
        Route::post('/garage/trigger', [GarageController::class, 'trigger'])
            ->middleware('throttle:device-capture');

        // User's devices (mobile-only — the /devices web routes are admin CRUD).
        Route::get('/devices', [ApiDeviceController::class, 'index']);
        Route::post('/devices/claim', [ApiDeviceClaimController::class, 'store']);
    });
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
