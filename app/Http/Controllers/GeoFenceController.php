<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

/**
 * GeoFenceController — V1.1
 *
 * Implements geo-fence management and proximity check endpoint.
 * All methods are stubbed; routes are registered so the API surface is stable.
 */
class GeoFenceController extends Controller
{
    /** GET /api/geo-fences — list user's geo-fences */
    public function index(Request $request)
    {
        return response()->json(['message' => 'Coming in V1.1'], 501);
    }

    /** POST /api/geo-fences — create a geo-fence */
    public function store(Request $request)
    {
        return response()->json(['message' => 'Coming in V1.1'], 501);
    }

    /** PUT /api/geo-fences/{geoFence} — update a geo-fence */
    public function update(Request $request, int $geoFence)
    {
        return response()->json(['message' => 'Coming in V1.1'], 501);
    }

    /** DELETE /api/geo-fences/{geoFence} — delete a geo-fence */
    public function destroy(Request $request, int $geoFence)
    {
        return response()->json(['message' => 'Coming in V1.1'], 501);
    }

    /**
     * POST /api/geo-fences/{geoFence}/check
     *
     * Called by the frontend with current lat/lng.
     * Evaluates whether the position triggers an entry/exit event
     * and fires CaptureService::trigger() on state transition.
     */
    public function check(Request $request, int $geoFence)
    {
        return response()->json(['message' => 'Coming in V1.1'], 501);
    }
}
