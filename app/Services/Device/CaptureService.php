<?php

namespace App\Services\Device;

use App\Contracts\DeviceInterface;
use App\Events\CaptureReady;
use App\Models\Device;
use App\Models\DeviceCapture;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

class CaptureService
{
    public function __construct(private DeviceInterface $device) {}

    /**
     * Trigger a capture on a specific device. Called by:
     *   - The dashboard CTA button (source = 'manual')
     *   - The geo-fence listener in V1.1 (source = 'geo_fence')
     *
     * In MQTT mode: creates a pending record, dispatches the MQTT command,
     * and returns immediately. The record is completed later when the ESP32
     * calls POST /api/device/captures/{id}/upload.
     *
     * In mock mode: simulates an immediate upload with a placeholder image.
     */
    public function trigger(User $user, Device $device, string $source = 'manual', ?int $geoFenceId = null): DeviceCapture
    {
        $capture = DeviceCapture::create([
            'user_id'        => $user->id,
            'device_id'      => $device->id,
            'trigger_source' => $source,
            'geo_fence_id'   => $geoFenceId,
            'media_type'     => 'image',
            'status'         => 'pending',
        ]);

        $dispatched = $this->device->requestCapture($device, $capture->id);

        if (! $dispatched) {
            $capture->update(['status' => 'failed', 'error_message' => 'Failed to dispatch capture command to device.']);
            return $capture;
        }

        // In mock mode, simulate the ESP32 callback immediately
        if (config('device.driver') === 'mock') {
            $this->completeMock($capture);
        }

        return $capture->fresh();
    }

    /**
     * Finalise a capture when the ESP32 POSTs the media back.
     * Called by DeviceCaptureController@upload.
     */
    public function finalise(DeviceCapture $capture, string $rawContent, string $contentType): DeviceCapture
    {
        $extension = str_contains($contentType, 'video') ? 'mp4' : 'jpg';
        $mediaType = str_contains($contentType, 'video') ? 'video' : 'image';
        $filename  = "captures/{$capture->id}.{$extension}";

        Storage::disk('public')->put($filename, $rawContent);

        $capture->update([
            'status'     => 'success',
            'media_type' => $mediaType,
            'media_path' => $filename,
            'media_url'  => Storage::disk('public')->url($filename),
        ]);

        broadcast(new CaptureReady($capture->fresh()))->toOthers();

        return $capture->fresh();
    }

    private function completeMock(DeviceCapture $capture): void
    {
        $placeholderPath = public_path('images/placeholder-capture.jpg');
        $content = file_exists($placeholderPath)
            ? file_get_contents($placeholderPath)
            : base64_decode('/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/2wBDAQkJCQwLDBgNDRgyIRwhMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjL/wAARCAABAAEDASIAAhEBAxEB/8QAFAABAAAAAAAAAAAAAAAAAAAACf/EABQQAQAAAAAAAAAAAAAAAAAAAAD/xAAUAQEAAAAAAAAAAAAAAAAAAAAA/8QAFBEBAAAAAAAAAAAAAAAAAAAAAP/aAAwDAQACEQMRAD8AJQAB/9k=');

        $this->finalise($capture, $content, 'image/jpeg');
    }
}
