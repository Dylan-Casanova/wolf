<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class StreamFrameReceived implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        private readonly int $streamId,
        private readonly string $frame,
    ) {}

    public function broadcastAs(): string
    {
        return 'StreamFrameReceived';
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("stream.{$this->streamId}"),
        ];
    }

    public function broadcastWith(): array
    {
        return [
            'frame' => $this->frame,
        ];
    }
}
