<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Contracts\DeviceInterface;
use App\Enums\StreamStatus;
use App\Events\StreamEnded;
use App\Models\Device;
use App\Models\Stream;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class StreamControllerTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function authenticated_user_can_start_stream(): void
    {
        $mock = Mockery::mock(DeviceInterface::class);
        $mock->shouldReceive('startStream')->once()->andReturn(true);
        $this->app->instance(DeviceInterface::class, $mock);

        $user = User::factory()->create();
        $device = Device::factory()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user)->postJson('/stream/start');

        $response->assertOk();
        $response->assertJsonStructure(['stream_id']);
        $this->assertDatabaseHas('streams', [
            'device_id' => $device->id,
            'user_id' => $user->id,
            'status' => 'pending',
        ]);
    }

    #[Test]
    public function user_without_device_cannot_start_stream(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/stream/start');

        $response->assertStatus(422);
    }

    #[Test]
    public function user_can_stop_stream(): void
    {
        $user = User::factory()->create();
        $device = Device::factory()->create(['user_id' => $user->id]);

        $mock = Mockery::mock(DeviceInterface::class);
        $mock->shouldReceive('stopStream')->once()->andReturn(true);
        $this->app->instance(DeviceInterface::class, $mock);

        $stream = Stream::create([
            'device_id' => $device->id,
            'user_id' => $user->id,
            'status' => 'active',
            'started_at' => now(),
        ]);

        $response = $this->actingAs($user)->postJson("/stream/{$stream->id}/stop");

        $response->assertOk();
        $this->assertEquals(StreamStatus::Ended, $stream->fresh()->status);
        $this->assertNotNull($stream->fresh()->ended_at);
    }

    #[Test]
    public function unauthenticated_user_cannot_start_stream(): void
    {
        $response = $this->postJson('/stream/start');

        $response->assertUnauthorized();
    }

    #[Test]
    public function stop_broadcasts_stream_ended_event(): void
    {
        Event::fake([StreamEnded::class]);

        $user = User::factory()->create();
        $device = Device::factory()->create(['user_id' => $user->id]);

        $mock = Mockery::mock(DeviceInterface::class);
        $mock->shouldReceive('stopStream')->once()->andReturn(true);
        $this->app->instance(DeviceInterface::class, $mock);

        $stream = Stream::create([
            'device_id' => $device->id,
            'user_id' => $user->id,
            'status' => 'active',
            'started_at' => now(),
        ]);

        $this->actingAs($user)->postJson("/stream/{$stream->id}/stop");

        Event::assertDispatched(StreamEnded::class, function ($event) use ($stream) {
            $payload = $event->broadcastWith();
            $channels = $event->broadcastOn();

            return $payload['reason'] === 'stopped'
                && $channels[0]->name === "private-stream.{$stream->id}";
        });
    }

    #[Test]
    public function stream_start_is_rate_limited_after_10_per_minute(): void
    {
        $user = User::factory()->create();
        Device::factory()->create(['user_id' => $user->id]);

        $mock = Mockery::mock(DeviceInterface::class);
        $mock->shouldReceive('startStream')->times(10)->andReturn(true);
        $this->app->instance(DeviceInterface::class, $mock);

        for ($i = 0; $i < 10; $i++) {
            $this->actingAs($user)->postJson('/stream/start')->assertOk();
        }

        $this->actingAs($user)->postJson('/stream/start')->assertStatus(429);
    }
}
