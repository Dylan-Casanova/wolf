<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Events\StreamEnded;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class StreamEndedTest extends TestCase
{
    #[Test]
    public function event_broadcasts_on_correct_channel(): void
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

    #[Test]
    public function payload_contains_reason(): void
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

    #[Test]
    public function broadcast_name_is_correct(): void
    {
        $event = new StreamEnded(1, 'stale');

        $this->assertEquals('StreamEnded', $event->broadcastAs());
    }

    #[Test]
    public function event_implements_should_broadcast_now(): void
    {
        $event = new StreamEnded(1, 'stopped');

        $this->assertInstanceOf(ShouldBroadcastNow::class, $event);
    }
}
