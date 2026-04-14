<?php

namespace App\Http\Controllers;

use App\Http\Resources\CaptureResource;
use App\Models\Device;
use App\Models\DeviceCapture;
use App\Services\Device\CaptureService;
use Illuminate\Http\Request;

class DeviceCaptureController extends Controller
{
    public function __construct(private CaptureService $captureService) {}

    /**
     * Trigger a new capture on a device.
     * Accepts optional device_id — defaults to user's first device.
     */
    public function store(Request $request)
    {
        $user = $request->user();

        // Find the target device
        $device = $request->device_id
            ? $user->devices()->where('id', $request->device_id)->firstOrFail()
            : $user->devices()->first();

        if (! $device) {
            $message = 'No device registered. Add a device first.';
            if ($request->wantsJson()) {
                return response()->json(['message' => $message], 422);
            }
            return back()->withErrors(['device' => $message]);
        }

        $capture = $this->captureService->trigger($user, $device);

        if ($request->wantsJson()) {
            return CaptureResource::make($capture);
        }

        return back()->with('capture', CaptureResource::make($capture)->resolve());
    }

    /**
     * Receive the media upload from the ESP32 after it processes the capture command.
     * Authenticated by device token in X-Device-Token header.
     */
    public function upload(Request $request, DeviceCapture $capture)
    {
        abort_if($capture->status !== 'pending', 409, 'Capture already processed.');

        // Verify device token
        $token = $request->header('X-Device-Token');
        if (! $token || ! $capture->device || ! $capture->device->verifyToken($token)) {
            abort(401, 'Invalid device token.');
        }

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
