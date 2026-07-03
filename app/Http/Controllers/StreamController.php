<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\StreamStatus;
use App\Models\Stream;
use App\Services\StreamService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StreamController extends Controller
{
    public function __construct(private StreamService $service) {}

    /**
     * Start a new stream — creates record, sends MQTT command.
     */
    public function start(Request $request): JsonResponse
    {
        $stream = $this->service->startFor($request->user());

        if (! $stream) {
            return response()->json(['message' => 'No device registered.'], 422);
        }

        return response()->json(['stream_id' => $stream->id]);
    }

    /**
     * Stop an active stream — sends MQTT command, cleans up.
     */
    public function stop(Request $request, Stream $stream): JsonResponse
    {
        if ($stream->status === StreamStatus::Ended) {
            return response()->json(['message' => 'Stream already ended.']);
        }

        $this->service->stop($stream);

        return response()->json(['message' => 'Stream stopped.']);
    }
}
