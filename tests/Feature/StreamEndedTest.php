<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Events\StreamEnded;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class StreamEndedTest extends TestCase
{
    public function test_event_broadcasts_on_correct_channel(): void
    {
        Event::fake([StreamEnded::class]);

        StreamEnded::dispatch(42, 'stopped');

        Event::assertDispatched(StreamEnded::class, function ($event) {
            $channels = $event->broadcastOn();
            $this->assertCount(1, $channels);
            $this->assertEquals('private-stream.42', $channels[0]->name);

            return true;
        });
    }

    public function test_payload_contains_reason(): void
    {
        Event::fake([StreamEnded::class]);

        StreamEnded::dispatch(7, 'timeout');

        Event::assertDispatched(StreamEnded::class, function ($event) {
            $data = $event->broadcastWith();
            $this->assertArrayHasKey('reason', $data);
            $this->assertEquals('timeout', $data['reason']);

            return true;
        });
    }

    public function test_broadcast_name_is_correct(): void
    {
        $event = new StreamEnded(1, 'stale');

        $this->assertEquals('StreamEnded', $event->broadcastAs());
    }

    public function test_event_implements_should_broadcast_now(): void
    {
        $event = new StreamEnded(1, 'stopped');

        $this->assertInstanceOf(ShouldBroadcastNow::class, $event);
    }
}
