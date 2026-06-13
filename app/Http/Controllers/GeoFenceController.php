<?php

namespace App\Http\Controllers;

use App\Contracts\DeviceInterface;
use App\Models\GeoFence;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GeoFenceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $geofence = $request->user()->geofence;

        return response()->json($geofence ? [$geofence] : []);
    }

    public function store(Request $request): JsonResponse
    {
        if ($request->user()->geofence) {
            return response()->json(['message' => 'Geofence already exists.'], 409);
        }

        $validated = $request->validate([
            'north_lat' => ['required', 'numeric', 'between:-90,90'],
            'south_lat' => ['required', 'numeric', 'between:-90,90'],
            'east_lng' => ['required', 'numeric', 'between:-180,180'],
            'west_lng' => ['required', 'numeric', 'between:-180,180'],
        ]);

        $geofence = $request->user()->geofence()->create($validated);

        return response()->json($geofence, 201);
    }

    public function update(Request $request, GeoFence $geoFence): JsonResponse
    {
        if ($geoFence->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $validated = $request->validate([
            'north_lat' => ['required', 'numeric', 'between:-90,90'],
            'south_lat' => ['required', 'numeric', 'between:-90,90'],
            'east_lng' => ['required', 'numeric', 'between:-180,180'],
            'west_lng' => ['required', 'numeric', 'between:-180,180'],
        ]);

        $geoFence->update($validated);

        return response()->json($geoFence);
    }

    public function destroy(Request $request, GeoFence $geoFence): JsonResponse
    {
        if ($geoFence->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $geoFence->delete();

        return response()->json(['message' => 'Geofence deleted.']);
    }

    public function toggle(Request $request, GeoFence $geoFence): JsonResponse
    {
        if ($geoFence->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $geoFence->update(['is_active' => ! $geoFence->is_active]);

        return response()->json(['is_active' => $geoFence->fresh()->is_active]);
    }

    public function check(Request $request, GeoFence $geoFence, DeviceInterface $device): JsonResponse
    {
        if ($geoFence->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $validated = $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
        ]);

        $distance = $geoFence->distanceFromCenter($validated['lat'], $validated['lng']);
        $inside = $geoFence->is_active && $geoFence->contains($validated['lat'], $validated['lng']);

        if ($inside) {
            $esp = $request->user()->devices()->where('type', 'esp8266')->first();

            if ($esp) {
                $device->triggerServo($esp);
            }

            $geoFence->update(['is_active' => false]);
        }

        return response()->json([
            'triggered' => $inside,
            'distance_meters' => round($distance),
        ]);
    }
}
