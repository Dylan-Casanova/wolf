<?php

declare(strict_types=1);

use App\Http\Controllers\Api\DeviceRegisterController;
use App\Http\Controllers\Api\DeviceStatusController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\GarageController;
use App\Http\Controllers\GeoFenceController;
use App\Http\Controllers\StreamFeedController;
use App\Models\Device;
use Illuminate\Http\Request;
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

        // Geofence (reuses the same controllers used by the web — both return JSON)
        Route::apiResource('geo-fences', GeoFenceController::class)
            ->only(['index', 'store', 'update', 'destroy']);
        Route::post('geo-fences/{geoFence}/check', [GeoFenceController::class, 'check']);
        Route::post('geo-fences/{geoFence}/toggle', [GeoFenceController::class, 'toggle']);

        // Garage trigger
        Route::post('/garage/trigger', [GarageController::class, 'trigger']);

        // User's devices (mobile-only — the existing /devices web routes are admin-only resource)
        Route::get('/devices', function (Request $request) {
            return response()->json(
                $request->user()->devices()->get()->map(fn ($d) => [
                    'id' => $d->id,
                    'name' => $d->name,
                    'device_id' => $d->device_id,
                    'type' => $d->type->value,
                    'is_online' => $d->is_online,
                    'last_seen_at' => $d->last_seen_at,
                    'meta' => $d->meta,
                ])
            );
        });

        Route::post('/devices/claim', function (Request $request) {
            $validated = $request->validate([
                'device_id' => ['required', 'string'],
            ]);

            $device = Device::where('device_id', $validated['device_id'])->first();

            if (! $device) {
                return response()->json(['message' => 'Device not found.'], 404);
            }

            if ($device->user_id === $request->user()->id) {
                return response()->json(['message' => 'You already own this device.'], 422);
            }

            if ($device->user_id !== null) {
                return response()->json(['message' => 'Device is already claimed.'], 422);
            }

            $device->update(['user_id' => $request->user()->id]);

            return response()->json($device);
        });
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
