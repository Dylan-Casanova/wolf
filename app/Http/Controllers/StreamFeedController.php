<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Events\StreamFrameReceived;
use App\Models\Stream;
use Illuminate\Http\Request;

class StreamFeedController extends Controller
{
    public function feed(Request $request, Stream $stream)
    {
        $token = $request->header('X-Device-Token');
        if (! $token || ! $stream->device || ! $stream->device->verifyToken($token)) {
            abort(401, 'Invalid device token.');
        }

        abort_if($stream->status === 'ended', 409, 'Stream already ended.');

        if ($stream->status === 'pending') {
            $stream->update(['status' => 'active', 'started_at' => now()]);
        }

        $frame = $request->getContent();
        if (strlen($frame) > 0) {
            broadcast(new StreamFrameReceived($stream->id, base64_encode($frame)));
        }

        return response()->json(['ok' => true]);
    }
}
