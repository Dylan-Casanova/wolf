<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ServoTriggered implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        private readonly int $deviceId,
    ) {}

    public function broadcastAs(): string
    {
        return 'ServoTriggered';
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel("device.{$this->deviceId}")];
    }

    public function broadcastWith(): array
    {
        return ['status' => 'done'];
    }
}
