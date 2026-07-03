<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\GeoFence\CheckGeoFenceRequest;
use App\Http\Requests\GeoFence\EstimateGeoFenceRequest;
use App\Http\Requests\GeoFence\ScheduleTriggerRequest;
use App\Http\Requests\GeoFence\StoreGeoFenceRequest;
use App\Http\Requests\GeoFence\UpdateGeoFenceRequest;
use App\Http\Resources\GeoFenceResource;
use App\Models\GeoFence;
use App\Services\GeoFenceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class GeoFenceController extends Controller
{
    public function __construct(private GeoFenceService $service) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $geofence = $request->user()->geofence;

        return GeoFenceResource::collection($geofence ? [$geofence] : []);
    }

    public function store(StoreGeoFenceRequest $request): JsonResponse
    {
        if ($request->user()->geofence) {
            return response()->json(['message' => 'Geofence already exists.'], 409);
        }

        $geofence = $this->service->createFor($request->user(), $request->validated());

        return GeoFenceResource::make($geofence)->response()->setStatusCode(201);
    }

    public function update(UpdateGeoFenceRequest $request, GeoFence $geoFence): GeoFenceResource
    {
        return GeoFenceResource::make($this->service->update($geoFence, $request->validated()));
    }

    public function destroy(Request $request, GeoFence $geoFence): JsonResponse
    {
        $this->authorize('delete', $geoFence);
        $this->service->delete($geoFence);

        return response()->json(['message' => 'Geofence deleted.']);
    }

    public function toggle(Request $request, GeoFence $geoFence): JsonResponse
    {
        $this->authorize('update', $geoFence);
        $this->service->toggle($geoFence);

        return response()->json(['is_active' => $geoFence->fresh()->is_active]);
    }

    public function check(CheckGeoFenceRequest $request, GeoFence $geoFence): JsonResponse
    {
        $validated = $request->validated();

        return response()->json(
            $this->service->check($request->user(), $geoFence, $validated['lat'], $validated['lng']),
        );
    }

    public function estimate(EstimateGeoFenceRequest $request, GeoFence $geoFence): JsonResponse
    {
        $validated = $request->validated();

        return response()->json(
            $this->service->estimate($geoFence, $validated['lat'], $validated['lng']),
        );
    }

    public function scheduleTrigger(ScheduleTriggerRequest $request, GeoFence $geoFence): JsonResponse
    {
        $validated = $request->validated();

        $trigger = $this->service->scheduleTrigger(
            $geoFence,
            $validated['minutes'],
            $validated['origin_lat'],
            $validated['origin_lng'],
        );

        return response()->json([
            'scheduled_trigger_id' => $trigger->id,
            'scheduled_at' => $trigger->scheduled_at->toIso8601String(),
            'fence' => ['is_active' => true],
        ]);
    }

    public function cancelScheduledTrigger(Request $request, GeoFence $geoFence): JsonResponse
    {
        $this->authorize('update', $geoFence);
        $this->service->cancelScheduledTrigger($geoFence);

        return response()->json(['fence' => ['is_active' => $geoFence->fresh()->is_active]]);
    }
}
