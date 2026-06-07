<?php

namespace Tests\Feature;

use App\Events\StreamFrameReceived;
use App\Models\Device;
use App\Models\Stream;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class StreamFeedTest extends TestCase
{
    use RefreshDatabase;

    public function test_feed_rejects_invalid_device_token(): void
    {
        $stream = Stream::factory()->create(['status' => 'pending']);

        $response = $this->postJson("/api/device/stream/{$stream->id}/feed", [], [
            'X-Device-Token' => 'wrong-token',
        ]);

        $response->assertUnauthorized();
    }

    public function test_feed_rejects_ended_stream(): void
    {
        $device = Device::factory()->create();
        $token = $device->generateToken();

        $stream = Stream::factory()->create([
            'device_id' => $device->id,
            'status' => 'ended',
        ]);

        $response = $this->postJson("/api/device/stream/{$stream->id}/feed", [], [
            'X-Device-Token' => $token,
        ]);

        $response->assertStatus(409);
    }

    public function test_feed_broadcasts_frame_event(): void
    {
        Event::fake([StreamFrameReceived::class]);

        $device = Device::factory()->create();
        $token = $device->generateToken();

        $stream = Stream::factory()->create([
            'device_id' => $device->id,
            'status' => 'active',
            'started_at' => now(),
        ]);

        $jpegData = 'fake-jpeg-binary-data';

        $response = $this->call('POST', "/api/device/stream/{$stream->id}/feed", [], [], [], [
            'HTTP_X_DEVICE_TOKEN' => $token,
            'CONTENT_TYPE' => 'image/jpeg',
        ], $jpegData);

        $response->assertOk();

        Event::assertDispatched(StreamFrameReceived::class, function ($event) use ($jpegData) {
            return $event->broadcastWith()['frame'] === base64_encode($jpegData);
        });
    }

    public function test_feed_marks_pending_stream_as_active(): void
    {
        Event::fake([StreamFrameReceived::class]);

        $device = Device::factory()->create();
        $token = $device->generateToken();

        $stream = Stream::factory()->create([
            'device_id' => $device->id,
            'status' => 'pending',
        ]);

        $this->call('POST', "/api/device/stream/{$stream->id}/feed", [], [], [], [
            'HTTP_X_DEVICE_TOKEN' => $token,
            'CONTENT_TYPE' => 'image/jpeg',
        ], 'jpeg-data');

        $this->assertEquals('active', $stream->fresh()->status);
        $this->assertNotNull($stream->fresh()->started_at);
    }

    public function test_feed_ignores_empty_body(): void
    {
        Event::fake([StreamFrameReceived::class]);

        $device = Device::factory()->create();
        $token = $device->generateToken();

        $stream = Stream::factory()->create([
            'device_id' => $device->id,
            'status' => 'active',
            'started_at' => now(),
        ]);

        $response = $this->call('POST', "/api/device/stream/{$stream->id}/feed", [], [], [], [
            'HTTP_X_DEVICE_TOKEN' => $token,
            'CONTENT_TYPE' => 'image/jpeg',
        ], '');

        $response->assertOk();
        Event::assertNotDispatched(StreamFrameReceived::class);
    }
}
