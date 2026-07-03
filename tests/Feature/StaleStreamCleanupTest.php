<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Events\StreamEnded;
use App\Models\Stream;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class StaleStreamCleanupTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function stale_active_streams_are_ended(): void
    {
        $stale = Stream::factory()->create([
            'status' => 'active',
            'started_at' => now()->subMinutes(5),
        ]);

        $fresh = Stream::factory()->create([
            'status' => 'active',
            'started_at' => now()->subSeconds(30),
        ]);

        $this->artisan('devices:check-stale')->assertSuccessful();

        $this->assertEquals('ended', $stale->fresh()->status);
        $this->assertNotNull($stale->fresh()->ended_at);
        $this->assertEquals('active', $fresh->fresh()->status);
    }

    #[Test]
    public function stale_pending_streams_are_ended(): void
    {
        $stale = Stream::factory()->create([
            'status' => 'pending',
            'created_at' => now()->subMinutes(5),
        ]);

        $this->artisan('devices:check-stale')->assertSuccessful();

        $this->assertEquals('ended', $stale->fresh()->status);
    }

    #[Test]
    public function ended_streams_older_than_24_hours_are_purged(): void
    {
        $old = Stream::factory()->create([
            'status' => 'ended',
            'ended_at' => now()->subHours(25),
        ]);

        $recent = Stream::factory()->create([
            'status' => 'ended',
            'ended_at' => now()->subHours(2),
        ]);

        $this->artisan('devices:check-stale')->assertSuccessful();

        $this->assertNull(Stream::find($old->id));
        $this->assertNotNull(Stream::find($recent->id));
    }

    #[Test]
    public function stale_streams_broadcast_ended_event(): void
    {
        Event::fake([StreamEnded::class]);

        $stale = Stream::factory()->create([
            'status' => 'active',
            'started_at' => now()->subMinutes(5),
        ]);

        $this->artisan('devices:check-stale')->assertSuccessful();

        Event::assertDispatched(StreamEnded::class, function ($event) use ($stale) {
            $payload = $event->broadcastWith();
            $channels = $event->broadcastOn();

            return $payload['reason'] === 'stale'
                && $channels[0]->name === "private-stream.{$stale->id}";
        });
    }
}
