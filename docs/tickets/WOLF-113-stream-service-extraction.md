# WOLF-097 · Extract `StreamService` — thin controllers, domain in the service

## Summary

`StreamController` and `StreamFeedController` inline the same domain
work `GeoFenceController` did pre-WOLF-112: MQTT commands, event
broadcasts, state transitions, model reads/writes. Extract into a
`StreamService`. After the refactor, both controllers become thin
HTTP orchestrators: extract input, delegate to service, shape response.

## Background

Current controllers post-WOLF-108:

**`StreamController::start`** — finds first user device, creates a
`Stream`, sends MQTT `startStream` command.

**`StreamController::stop`** — idempotence check, sends MQTT
`stopStream`, updates state to `Ended`, broadcasts `StreamEnded('stopped')`.

**`StreamFeedController::feed`** — device-token auth via request
header, terminal-state guard, `Pending → Active` transition on first
frame, broadcasts `StreamFrameReceived`.

The `DeviceInterface` is method-injected into `StreamController` via
constructor — same pattern GeoFenceController had before WOLF-112.
`StreamFeedController` today has no DI beyond framework auto-wiring.

## Failure modes / signal

1. **Duplicated MQTT / broadcast logic** across controllers, jobs, and
   `CheckStaleDevicesCommand` (which also ends streams). If the
   `StreamEnded` event's payload changes, three files change.
2. **Controller does state transitions inline.** `Pending → Active` in
   `StreamFeedController::feed` is a domain rule, not an HTTP concern.
3. **`CheckStaleDevicesCommand` re-implements stream ending.** Setting
   `status = Ended`, `ended_at = now()`, and broadcasting `StreamEnded`
   are duplicated. WOLF-113 gives them one method to share.
4. **Consistency with WOLF-112.** Half the domain has a service, half
   doesn't. Finishing the pattern makes the codebase read consistently.

## Solution

**New file** `app/Services/StreamService.php`:

```php
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
```

**`StreamController` after refactor** — no `DeviceInterface` import,
no `StreamEnded` import, no `now()` calls in the write path:

```php
class StreamController extends Controller
{
    public function __construct(private StreamService $service) {}

    public function start(Request $request): JsonResponse
    {
        $stream = $this->service->startFor($request->user());

        if (! $stream) {
            return response()->json(['message' => 'No device registered.'], 422);
        }

        return response()->json(['stream_id' => $stream->id]);
    }

    public function stop(Request $request, Stream $stream): JsonResponse
    {
        if ($stream->status === StreamStatus::Ended) {
            return response()->json(['message' => 'Stream already ended.']);
        }

        $this->service->stop($stream);

        return response()->json(['message' => 'Stream stopped.']);
    }
}
```

**`StreamFeedController` after refactor** — device-token check
stays (it's HTTP-boundary auth), state transition + broadcast go to
service:

```php
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
```

## Design decisions to defend

1. **`startFor()` returns `?Stream` (null on no-device).** Not an error
   — a user simply may not have a device yet. Alternatives considered:
   - `NoDeviceRegisteredException` — proper domain modeling but adds
     an exception class for a case the controller trivially handles.
   - `Result` object — heavier than the two-line switch the controller
     does today.
   Null is pragmatic and defensible; can be revisited if the null path
   grows more branches.
2. **Idempotent `stop()`.** Safe to call on already-ended streams. The
   controller keeps a status-check to shape the response ("already
   ended" vs "stopped") — response shape is HTTP concern, so it stays
   at the boundary.
3. **`stop($stream, string $reason = 'stopped')`.** The `reason`
   parameter lets `CheckStaleDevicesCommand` (which currently
   broadcasts `StreamEnded($stream->id, 'stale')`) reuse the method.
   Default matches the controller's current behavior.
4. **Device-token auth stays in `StreamFeedController`.** It reads
   `$request->header('X-Device-Token')` — pure HTTP boundary concern.
   Moving it to the service would leak Request into the domain.
   Could later become middleware; separate ticket.
5. **`markActiveIfPending()` and `broadcastFrame()` as two service
   methods, not one bundled `handleFrame()`.** The controller decides
   *whether* to broadcast based on frame content (empty frames still
   transition state but skip broadcast). Two methods keep the
   controller in charge of that policy without service knowing about
   HTTP request bodies.
6. **`base64_encode()` stays in the service.** It's a
   protocol-shaping concern about how frames travel over the wire —
   arguably in the event payload, not the service. Kept in service
   for now since it's the current call site; move to the event class
   if it accretes more consumers.

## Behavior guarantees

- Wire-format preserved for `/stream/start`, `/stream/{stream}/stop`,
  and the `/api/device/stream/{stream}/feed` endpoint.
- `StreamEnded` event's payload preserved (default reason `'stopped'`).
- `StreamFrameReceived` event's payload preserved (base64-encoded frame).
- All state transitions (`Pending → Active`, `Active → Ended`,
  `Pending → Ended`) preserved.

## Acceptance criteria

- [ ] `app/Services/StreamService.php` exists with four public methods
      declared above.
- [ ] `StreamController` no longer imports `DeviceInterface`,
      `StreamEnded`, or `now()` in its `use` block. Constructor DI
      is now `StreamService`.
- [ ] `StreamFeedController` no longer imports `StreamFrameReceived`.
      Constructor DI is now `StreamService`.
- [ ] `composer test` reports 145/145 unchanged.
- [ ] No `broadcast(new StreamEnded(...))` or `broadcast(new
      StreamFrameReceived(...))` calls remain in any controller.

## Out of scope

- **`CheckStaleDevicesCommand` refactor to use the service.**
  Defensible but expands the ticket. The command's inline shape
  parallels the controllers well enough; can pull into a follow-up
  once the pattern is proven.
- **`NoDeviceRegisteredException` domain modeling.** Deferred as
  discussed above.
- **Device-token authentication → middleware.** Separate ticket.
- **`StreamResource` for JSON responses.** Deferred to a future
  ticket when a full stream payload is required (today only
  `stream_id` is returned).
- **`StreamPolicy`** — `StreamFeedController::feed` uses device-token
  auth (not user-session auth) — a Policy doesn't apply. Stream
  ownership guards aren't currently duplicated across controllers, so
  no Policy is warranted here.

## Effort breakdown

| Step | Estimate |
|---|---|
| Create `StreamService.php` | 15 min |
| Refactor `StreamController` and `StreamFeedController` | 15 min |
| Run test suite; fix any wire drift | 10 min |
| Grep verification | 5 min |

## Sequencing

Second ticket in Wave 3. Independent from WOLF-112 (different domain)
and WOLF-114 (different domain).

## Notes

- **Rollback risk:** low. Test coverage on Stream flows is solid
  (`StreamControllerTest`, `StreamFeedTest`, `StreamModelTest`,
  `StaleStreamCleanupTest`). If a shape regresses, tests catch it.
- **Interview signal:** consistency. If asked "why does GeoFence have
  a service but Stream doesn't?" — no good answer today. This ticket
  eliminates the question.
