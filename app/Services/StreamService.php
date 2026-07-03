<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\DeviceInterface;
use App\Enums\StreamStatus;
use App\Events\StreamEnded;
use App\Events\StreamFrameReceived;
use App\Models\Stream;
use App\Models\User;

class StreamService
{
    public function __construct(private DeviceInterface $device) {}

    /**
     * Start a new stream for the user's first device.
     * Returns null if the user has no device registered.
     */
    public function startFor(User $user): ?Stream
    {
        $device = $user->devices()->first();
        if (! $device) {
            return null;
        }

        $stream = Stream::create([
            'device_id' => $device->id,
            'user_id' => $user->id,
            'status' => StreamStatus::Pending,
        ]);

        $this->device->startStream($device, $stream->id);

        return $stream;
    }

    /**
     * Terminate a stream. Idempotent — safe to call on an already-
     * ended stream (returns without side-effects).
     */
    public function stop(Stream $stream, string $reason = 'stopped'): void
    {
        if ($stream->status === StreamStatus::Ended) {
            return;
        }

        $this->device->stopStream($stream->device);

        $stream->update([
            'status' => StreamStatus::Ended,
            'ended_at' => now(),
        ]);

        broadcast(new StreamEnded($stream->id, $reason));
    }

    /**
     * Transitions Pending → Active on first observed frame.
     * No-op on any other state.
     */
    public function markActiveIfPending(Stream $stream): void
    {
        if ($stream->status === StreamStatus::Pending) {
            $stream->update([
                'status' => StreamStatus::Active,
                'started_at' => now(),
            ]);
        }
    }

    public function broadcastFrame(Stream $stream, string $frame): void
    {
        broadcast(new StreamFrameReceived($stream->id, base64_encode($frame)));
    }
}
