<?php

namespace App\Events;

use App\Models\Device;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class DeviceStatusChanged implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public readonly Device $device) {}

    public function broadcastAs(): string
    {
        return 'DeviceStatusChanged';
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('devices'),
        ];
    }

    public function broadcastWith(): array
    {
        return [
            'device_id' => $this->device->id,
            'is_online' => $this->device->is_online,
            'last_seen_at' => $this->device->last_seen_at?->toISOString(),
        ];
    }
}
