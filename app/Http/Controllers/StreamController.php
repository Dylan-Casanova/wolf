<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Contracts\DeviceInterface;
use App\Enums\StreamStatus;
use App\Events\StreamEnded;
use App\Models\Stream;
use Illuminate\Http\Request;

class StreamController extends Controller
{
    public function __construct(private DeviceInterface $device) {}

    /**
     * Start a new stream — creates record, sends MQTT command.
     */
    public function start(Request $request)
    {
        $user = $request->user();
        $device = $user->devices()->first();

        if (! $device) {
            return response()->json(['message' => 'No device registered.'], 422);
        }

        $stream = Stream::create([
            'device_id' => $device->id,
            'user_id' => $user->id,
            'status' => StreamStatus::Pending,
        ]);

        $this->device->startStream($device, $stream->id);

        return response()->json(['stream_id' => $stream->id]);
    }

    /**
     * Stop an active stream — sends MQTT command, cleans up.
     */
    public function stop(Request $request, Stream $stream)
    {
        if ($stream->status === StreamStatus::Ended) {
            return response()->json(['message' => 'Stream already ended.']);
        }

        $this->device->stopStream($stream->device);

        $stream->update([
            'status' => StreamStatus::Ended,
            'ended_at' => now(),
        ]);

        broadcast(new StreamEnded($stream->id, 'stopped'));

        return response()->json(['message' => 'Stream stopped.']);
    }
}
