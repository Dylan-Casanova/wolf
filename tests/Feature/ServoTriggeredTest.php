<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Events\ServoTriggered;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Tests\TestCase;

class ServoTriggeredTest extends TestCase
{
    public function test_it_implements_should_broadcast_now(): void
    {
        $event = new ServoTriggered(1);

        $this->assertInstanceOf(ShouldBroadcastNow::class, $event);
    }

    public function test_it_broadcasts_on_private_device_channel(): void
    {
        $event = new ServoTriggered(42);

        $channels = $event->broadcastOn();

        $this->assertCount(1, $channels);
        $this->assertInstanceOf(PrivateChannel::class, $channels[0]);
        $this->assertEquals('private-device.42', $channels[0]->name);
    }

    public function test_it_broadcasts_correct_payload(): void
    {
        $event = new ServoTriggered(1);

        $this->assertEquals(['status' => 'done'], $event->broadcastWith());
    }

    public function test_it_broadcasts_as_servo_triggered(): void
    {
        $event = new ServoTriggered(1);

        $this->assertEquals('ServoTriggered', $event->broadcastAs());
    }
}
