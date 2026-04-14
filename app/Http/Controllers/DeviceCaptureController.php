<?php

namespace App\Http\Controllers;

use App\Http\Resources\CaptureResource;
use App\Models\DeviceCapture;
use App\Services\Device\CaptureService;
use Illuminate\Http\Request;

class DeviceCaptureController extends Controller
{
    public function __construct(private CaptureService $captureService) {}

    /**
     * Trigger a new capture.
     * Web (Inertia): redirects back with flash data.
     * API (React Native / JSON): returns CaptureResource.
     */
    public function store(Request $request)
    {
        $capture = $this->captureService->trigger($request->user());

        if ($request->wantsJson()) {
            return CaptureResource::make($capture);
        }

        return back()->with('capture', CaptureResource::make($capture)->resolve());
    }

    /**
     * Receive the media upload from the ESP32 after it processes the capture command.
     * The ESP32 calls this endpoint directly with the raw binary payload.
     */
    public function upload(Request $request, DeviceCapture $capture)
    {
        // Verify the capture belongs to a valid user and is still pending
        abort_if($capture->status !== 'pending', 409, 'Capture already processed.');

        $rawContent  = $request->getContent();
        $contentType = $request->header('Content-Type', 'image/jpeg');

        $finalised = $this->captureService->finalise($capture, $rawContent, $contentType);

        return CaptureResource::make($finalised);
    }

    /**
     * Paginated capture history for the authenticated user.
     */
    public function index(Request $request)
    {
        $captures = $request->user()
            ->captures()
            ->latest()
            ->paginate(20);

        return CaptureResource::collection($captures);
    }
}
