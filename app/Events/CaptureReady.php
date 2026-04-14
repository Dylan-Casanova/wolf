<?php

namespace App\Events;

use App\Models\DeviceCapture;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CaptureReady implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public readonly DeviceCapture $capture) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("user.{$this->capture->user_id}"),
        ];
    }

    public function broadcastWith(): array
    {
        return [
            'id'         => $this->capture->id,
            'media_type' => $this->capture->media_type,
            'media_url'  => $this->capture->media_url,
            'status'     => $this->capture->status,
        ];
    }
}
