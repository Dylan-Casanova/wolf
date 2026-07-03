<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Device;
use App\Models\Stream;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class StreamModelTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function stream_belongs_to_device_and_user(): void
    {
        $user = User::factory()->create();
        $device = Device::factory()->create(['user_id' => $user->id]);

        $stream = Stream::create([
            'device_id' => $device->id,
            'user_id' => $user->id,
            'status' => 'pending',
        ]);

        $this->assertEquals($device->id, $stream->device->id);
        $this->assertEquals($user->id, $stream->user->id);
    }

    #[Test]
    public function stream_has_correct_statuses(): void
    {
        $stream = Stream::factory()->create(['status' => 'pending']);
        $this->assertEquals('pending', $stream->status);

        $stream->update(['status' => 'active', 'started_at' => now()]);
        $this->assertEquals('active', $stream->fresh()->status);

        $stream->update(['status' => 'ended', 'ended_at' => now()]);
        $this->assertEquals('ended', $stream->fresh()->status);
    }
}
