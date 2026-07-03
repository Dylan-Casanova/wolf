<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\StreamStatus;
use App\Models\Stream;
use App\Services\StreamService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StreamFeedController extends Controller
{
    public function __construct(private StreamService $service) {}

    public function feed(Request $request, Stream $stream): JsonResponse
    {
        $token = $request->header('X-Device-Token');
        if (! $token || ! $stream->device || ! $stream->device->verifyToken($token)) {
            abort(401, 'Invalid device token.');
        }

        abort_if($stream->status === StreamStatus::Ended, 409, 'Stream already ended.');

        $this->service->markActiveIfPending($stream);

        $frame = $request->getContent();
        if (strlen($frame) > 0) {
            $this->service->broadcastFrame($stream, $frame);
        }

        return response()->json(['ok' => true]);
    }
}
