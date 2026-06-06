<?php

namespace Tests\Feature;

use App\Events\StreamFrameReceived;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class StreamFrameReceivedTest extends TestCase
{
    public function test_event_broadcasts_on_correct_channel(): void
    {
        Event::fake([StreamFrameReceived::class]);

        $streamId = 42;
        $frame = base64_encode('fake-jpeg-data');

        StreamFrameReceived::dispatch($streamId, $frame);

        Event::assertDispatched(StreamFrameReceived::class, function ($event) use ($streamId) {
            $channels = $event->broadcastOn();
            $this->assertCount(1, $channels);
            $this->assertEquals("private-stream.{$streamId}", $channels[0]->name);

            return true;
        });
    }

    public function test_broadcast_payload_contains_frame(): void
    {
        Event::fake([StreamFrameReceived::class]);

        $streamId = 7;
        $frame = base64_encode('jpeg-bytes-here');

        StreamFrameReceived::dispatch($streamId, $frame);

        Event::assertDispatched(StreamFrameReceived::class, function ($event) use ($frame) {
            $data = $event->broadcastWith();
            $this->assertArrayHasKey('frame', $data);
            $this->assertEquals($frame, $data['frame']);

            return true;
        });
    }

    public function test_broadcast_name_is_correct(): void
    {
        $event = new StreamFrameReceived(1, base64_encode('data'));

        $this->assertEquals('StreamFrameReceived', $event->broadcastAs());
    }

    public function test_event_implements_should_broadcast_now(): void
    {
        $event = new StreamFrameReceived(1, base64_encode('data'));

        $this->assertInstanceOf(ShouldBroadcastNow::class, $event);
    }
}
