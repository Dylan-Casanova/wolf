# WebSocket Streaming Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace temp file + MJPEG streaming with Reverb WebSocket broadcasting so frames reach the browser reliably.

**Architecture:** ESP32 POSTs JPEG frames to the existing `feed()` endpoint. Instead of writing to a temp file, the controller base64-encodes the frame and broadcasts a `StreamFrameReceived` event via Reverb. The browser receives frames through Laravel Echo and renders them as data URIs. A `StreamEnded` event notifies the browser when the stream stops.

**Tech Stack:** Laravel Reverb, Laravel Echo, Pusher.js, React/TypeScript

**Spec:** `docs/superpowers/specs/2026-06-05-websocket-streaming-design.md`

---

### Task 1: Publish Reverb Config and Set Max Message Size

**Files:**
- Create: `config/reverb.php`

- [ ] **Step 1: Publish the Reverb config**

Run inside the app container:
```bash
docker compose exec -T app php artisan reverb:install
```

If this creates `config/reverb.php`, proceed. If it already exists or the command adds other files, only keep `config/reverb.php`.

- [ ] **Step 2: Set max_message_size to 150KB**

In `config/reverb.php`, find the `apps` array and add `'max_message_size' => 153600` to the app config:

```php
'apps' => [
    [
        'app_id' => env('REVERB_APP_ID'),
        'key' => env('REVERB_APP_KEY'),
        'secret' => env('REVERB_APP_SECRET'),
        'options' => [
            'host' => env('REVERB_HOST'),
            'port' => env('REVERB_PORT', 8080),
            'scheme' => env('REVERB_SCHEME', 'http'),
        ],
        'max_message_size' => 153600,
        'allowed_origins' => ['*'],
    ],
],
```

- [ ] **Step 3: Verify Reverb starts with new config**

```bash
docker compose restart reverb
docker compose logs reverb --tail=5
```

Expected: Reverb starts without errors.

- [ ] **Step 4: Commit**

```bash
git add config/reverb.php
git commit -m "feat: publish reverb config with 150KB max message size for streaming"
```

---

### Task 2: Create StreamFrameReceived Event

**Files:**
- Create: `app/Events/StreamFrameReceived.php`
- Test: `tests/Feature/StreamFrameReceivedTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/StreamFrameReceivedTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Events\StreamFrameReceived;
use App\Models\Stream;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class StreamFrameReceivedTest extends TestCase
{
    use RefreshDatabase;

    public function test_event_broadcasts_on_correct_channel(): void
    {
        $stream = Stream::factory()->create();
        $frame = base64_encode('fake-jpeg-data');

        $event = new StreamFrameReceived($stream->id, $frame);

        $channels = $event->broadcastOn();
        $this->assertCount(1, $channels);
        $this->assertEquals("private-stream.{$stream->id}", $channels[0]->name);
    }

    public function test_event_payload_contains_frame(): void
    {
        $frame = base64_encode('fake-jpeg-data');

        $event = new StreamFrameReceived(1, $frame);

        $payload = $event->broadcastWith();
        $this->assertEquals($frame, $payload['frame']);
    }

    public function test_event_uses_correct_broadcast_name(): void
    {
        $event = new StreamFrameReceived(1, 'data');

        $this->assertEquals('StreamFrameReceived', $event->broadcastAs());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
docker compose exec -T app php artisan test --filter="StreamFrameReceivedTest"
```

Expected: FAIL — class not found.

- [ ] **Step 3: Write the event class**

Create `app/Events/StreamFrameReceived.php`:

```php
<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

class StreamFrameReceived implements ShouldBroadcastNow
{
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
```

- [ ] **Step 4: Run test to verify it passes**

```bash
docker compose exec -T app php artisan test --filter="StreamFrameReceivedTest"
```

Expected: 3 tests pass.

- [ ] **Step 5: Commit**

```bash
git add app/Events/StreamFrameReceived.php tests/Feature/StreamFrameReceivedTest.php
git commit -m "feat: add StreamFrameReceived broadcast event"
```

---

### Task 3: Create StreamEnded Event

**Files:**
- Create: `app/Events/StreamEnded.php`
- Test: `tests/Feature/StreamEndedTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/StreamEndedTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Events\StreamEnded;
use App\Models\Stream;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StreamEndedTest extends TestCase
{
    use RefreshDatabase;

    public function test_event_broadcasts_on_correct_channel(): void
    {
        $stream = Stream::factory()->create();

        $event = new StreamEnded($stream->id, 'stopped');

        $channels = $event->broadcastOn();
        $this->assertCount(1, $channels);
        $this->assertEquals("private-stream.{$stream->id}", $channels[0]->name);
    }

    public function test_event_payload_contains_reason(): void
    {
        $event = new StreamEnded(1, 'timeout');

        $payload = $event->broadcastWith();
        $this->assertEquals('timeout', $payload['reason']);
    }

    public function test_event_uses_correct_broadcast_name(): void
    {
        $event = new StreamEnded(1, 'stopped');

        $this->assertEquals('StreamEnded', $event->broadcastAs());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
docker compose exec -T app php artisan test --filter="StreamEndedTest"
```

Expected: FAIL — class not found.

- [ ] **Step 3: Write the event class**

Create `app/Events/StreamEnded.php`:

```php
<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

class StreamEnded implements ShouldBroadcastNow
{
    public function __construct(
        private readonly int $streamId,
        private readonly string $reason,
    ) {}

    public function broadcastAs(): string
    {
        return 'StreamEnded';
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
            'reason' => $this->reason,
        ];
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

```bash
docker compose exec -T app php artisan test --filter="StreamEndedTest"
```

Expected: 3 tests pass.

- [ ] **Step 5: Commit**

```bash
git add app/Events/StreamEnded.php tests/Feature/StreamEndedTest.php
git commit -m "feat: add StreamEnded broadcast event"
```

---

### Task 4: Add Channel Authorization for stream.{streamId}

**Files:**
- Modify: `routes/channels.php`
- Test: `tests/Feature/StreamChannelAuthTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/StreamChannelAuthTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Stream;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StreamChannelAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_stream_owner_can_access_channel(): void
    {
        $user = User::factory()->create();
        $stream = Stream::factory()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user)->postJson('/broadcasting/auth', [
            'channel_name' => "private-stream.{$stream->id}",
        ]);

        $response->assertOk();
    }

    public function test_non_owner_cannot_access_channel(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $stream = Stream::factory()->create(['user_id' => $owner->id]);

        $response = $this->actingAs($other)->postJson('/broadcasting/auth', [
            'channel_name' => "private-stream.{$stream->id}",
        ]);

        $response->assertForbidden();
    }

    public function test_unauthenticated_user_cannot_access_channel(): void
    {
        $stream = Stream::factory()->create();

        $response = $this->postJson('/broadcasting/auth', [
            'channel_name' => "private-stream.{$stream->id}",
        ]);

        $response->assertUnauthorized();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
docker compose exec -T app php artisan test --filter="StreamChannelAuthTest"
```

Expected: FAIL — channel not authorized (returns 403 for owner).

- [ ] **Step 3: Add the channel authorization**

In `routes/channels.php`, add after the existing `devices` channel:

```php
Broadcast::channel('stream.{streamId}', function ($user, $streamId) {
    $stream = \App\Models\Stream::find($streamId);

    return $stream && (int) $user->id === (int) $stream->user_id;
});
```

- [ ] **Step 4: Run test to verify it passes**

```bash
docker compose exec -T app php artisan test --filter="StreamChannelAuthTest"
```

Expected: 3 tests pass.

- [ ] **Step 5: Commit**

```bash
git add routes/channels.php tests/Feature/StreamChannelAuthTest.php
git commit -m "feat: add stream channel authorization"
```

---

### Task 5: Update StreamFeedController to Broadcast Instead of Temp File

**Files:**
- Modify: `app/Http/Controllers/StreamFeedController.php`
- Modify: `tests/Feature/StreamFeedTest.php`

- [ ] **Step 1: Update the feed test to assert broadcasting**

Replace `tests/Feature/StreamFeedTest.php` with:

```php
<?php

namespace Tests\Feature;

use App\Events\StreamFrameReceived;
use App\Models\Device;
use App\Models\Stream;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class StreamFeedTest extends TestCase
{
    use RefreshDatabase;

    public function test_feed_rejects_invalid_device_token(): void
    {
        $stream = Stream::factory()->create(['status' => 'pending']);

        $response = $this->postJson("/api/device/stream/{$stream->id}/feed", [], [
            'X-Device-Token' => 'wrong-token',
        ]);

        $response->assertUnauthorized();
    }

    public function test_feed_rejects_ended_stream(): void
    {
        $device = Device::factory()->create();
        $token = $device->generateToken();

        $stream = Stream::factory()->create([
            'device_id' => $device->id,
            'status' => 'ended',
        ]);

        $response = $this->postJson("/api/device/stream/{$stream->id}/feed", [], [
            'X-Device-Token' => $token,
        ]);

        $response->assertStatus(409);
    }

    public function test_feed_broadcasts_frame_event(): void
    {
        Event::fake([StreamFrameReceived::class]);

        $device = Device::factory()->create();
        $token = $device->generateToken();

        $stream = Stream::factory()->create([
            'device_id' => $device->id,
            'status' => 'active',
            'started_at' => now(),
        ]);

        $jpegData = 'fake-jpeg-binary-data';

        $response = $this->call('POST', "/api/device/stream/{$stream->id}/feed", [], [], [], [
            'HTTP_X_DEVICE_TOKEN' => $token,
            'CONTENT_TYPE' => 'image/jpeg',
        ], $jpegData);

        $response->assertOk();

        Event::assertDispatched(StreamFrameReceived::class, function ($event) use ($jpegData) {
            return $event->broadcastWith()['frame'] === base64_encode($jpegData);
        });
    }

    public function test_feed_marks_pending_stream_as_active(): void
    {
        Event::fake([StreamFrameReceived::class]);

        $device = Device::factory()->create();
        $token = $device->generateToken();

        $stream = Stream::factory()->create([
            'device_id' => $device->id,
            'status' => 'pending',
        ]);

        $this->call('POST', "/api/device/stream/{$stream->id}/feed", [], [], [], [
            'HTTP_X_DEVICE_TOKEN' => $token,
            'CONTENT_TYPE' => 'image/jpeg',
        ], 'jpeg-data');

        $this->assertEquals('active', $stream->fresh()->status);
        $this->assertNotNull($stream->fresh()->started_at);
    }

    public function test_feed_ignores_empty_body(): void
    {
        Event::fake([StreamFrameReceived::class]);

        $device = Device::factory()->create();
        $token = $device->generateToken();

        $stream = Stream::factory()->create([
            'device_id' => $device->id,
            'status' => 'active',
            'started_at' => now(),
        ]);

        $response = $this->call('POST', "/api/device/stream/{$stream->id}/feed", [], [], [], [
            'HTTP_X_DEVICE_TOKEN' => $token,
            'CONTENT_TYPE' => 'image/jpeg',
        ], '');

        $response->assertOk();
        Event::assertNotDispatched(StreamFrameReceived::class);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
docker compose exec -T app php artisan test --filter="StreamFeedTest"
```

Expected: New tests fail (old ones may still pass since controller hasn't changed yet).

- [ ] **Step 3: Rewrite StreamFeedController**

Replace `app/Http/Controllers/StreamFeedController.php` with:

```php
<?php

namespace App\Http\Controllers;

use App\Events\StreamFrameReceived;
use App\Models\Stream;
use Illuminate\Http\Request;

class StreamFeedController extends Controller
{
    /**
     * ESP32 pushes one JPEG frame per request.
     * Base64-encodes the frame and broadcasts it via Reverb.
     */
    public function feed(Request $request, Stream $stream)
    {
        // Verify device token
        $token = $request->header('X-Device-Token');
        if (! $token || ! $stream->device || ! $stream->device->verifyToken($token)) {
            abort(401, 'Invalid device token.');
        }

        abort_if($stream->status === 'ended', 409, 'Stream already ended.');

        // Mark stream as active on first frame
        if ($stream->status === 'pending') {
            $stream->update(['status' => 'active', 'started_at' => now()]);
        }

        // Broadcast frame to browser via Reverb
        $frame = $request->getContent();
        if (strlen($frame) > 0) {
            broadcast(new StreamFrameReceived($stream->id, base64_encode($frame)));
        }

        return response()->json(['ok' => true]);
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

```bash
docker compose exec -T app php artisan test --filter="StreamFeedTest"
```

Expected: 5 tests pass.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/StreamFeedController.php tests/Feature/StreamFeedTest.php
git commit -m "feat: replace temp file I/O with Reverb broadcast in feed endpoint"
```

---

### Task 6: Update StreamController to Broadcast StreamEnded and Remove Temp File Cleanup

**Files:**
- Modify: `app/Http/Controllers/StreamController.php`
- Modify: `tests/Feature/StreamControllerTest.php`

- [ ] **Step 1: Update the stop test to assert StreamEnded broadcast**

Add this test to `tests/Feature/StreamControllerTest.php` (add `use App\Events\StreamEnded;` and `use Illuminate\Support\Facades\Event;` to imports):

```php
public function test_stop_broadcasts_stream_ended_event(): void
{
    Event::fake([StreamEnded::class]);

    $user = User::factory()->create();
    $device = Device::factory()->create(['user_id' => $user->id]);

    $mock = Mockery::mock(DeviceInterface::class);
    $mock->shouldReceive('stopStream')->once()->andReturn(true);
    $this->app->instance(DeviceInterface::class, $mock);

    $stream = Stream::create([
        'device_id' => $device->id,
        'user_id' => $user->id,
        'status' => 'active',
        'started_at' => now(),
    ]);

    $this->actingAs($user)->postJson("/stream/{$stream->id}/stop");

    Event::assertDispatched(StreamEnded::class, function ($event) use ($stream) {
        $payload = $event->broadcastWith();
        $channels = $event->broadcastOn();

        return $payload['reason'] === 'stopped'
            && $channels[0]->name === "private-stream.{$stream->id}";
    });
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
docker compose exec -T app php artisan test --filter="test_stop_broadcasts_stream_ended_event"
```

Expected: FAIL — StreamEnded not dispatched.

- [ ] **Step 3: Update StreamController**

Replace `app/Http/Controllers/StreamController.php` with:

```php
<?php

namespace App\Http\Controllers;

use App\Contracts\DeviceInterface;
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
            'status' => 'pending',
        ]);

        $this->device->startStream($device, $stream->id);

        return response()->json(['stream_id' => $stream->id]);
    }

    /**
     * Stop an active stream — sends MQTT command, broadcasts ended event.
     */
    public function stop(Request $request, Stream $stream)
    {
        if ($stream->status === 'ended') {
            return response()->json(['message' => 'Stream already ended.']);
        }

        $this->device->stopStream($stream->device);

        $stream->update([
            'status' => 'ended',
            'ended_at' => now(),
        ]);

        broadcast(new StreamEnded($stream->id, 'stopped'));

        return response()->json(['message' => 'Stream stopped.']);
    }
}
```

- [ ] **Step 4: Run all StreamController tests to verify they pass**

```bash
docker compose exec -T app php artisan test --filter="StreamControllerTest"
```

Expected: 5 tests pass.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/StreamController.php tests/Feature/StreamControllerTest.php
git commit -m "feat: broadcast StreamEnded event on stop, remove temp file cleanup"
```

---

### Task 7: Update CheckStaleDevicesCommand to Broadcast StreamEnded and Remove Temp File Cleanup

**Files:**
- Modify: `app/Console/Commands/CheckStaleDevicesCommand.php`
- Modify: `tests/Feature/StaleStreamCleanupTest.php`

- [ ] **Step 1: Update stale test to assert StreamEnded broadcast**

Add this test to `tests/Feature/StaleStreamCleanupTest.php` (add `use App\Events\StreamEnded;` and `use Illuminate\Support\Facades\Event;` to imports):

```php
public function test_stale_streams_broadcast_ended_event(): void
{
    Event::fake([StreamEnded::class]);

    $stale = Stream::factory()->create([
        'status' => 'active',
        'started_at' => now()->subMinutes(5),
    ]);

    $this->artisan('devices:check-stale')->assertSuccessful();

    Event::assertDispatched(StreamEnded::class, function ($event) use ($stale) {
        $payload = $event->broadcastWith();
        $channels = $event->broadcastOn();

        return $payload['reason'] === 'stale'
            && $channels[0]->name === "private-stream.{$stale->id}";
    });
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
docker compose exec -T app php artisan test --filter="test_stale_streams_broadcast_ended_event"
```

Expected: FAIL — StreamEnded not dispatched.

- [ ] **Step 3: Update CheckStaleDevicesCommand**

Replace the `cleanStaleStreams()` method in `app/Console/Commands/CheckStaleDevicesCommand.php` with:

```php
private function cleanStaleStreams(): void
{
    // End streams that are active for more than 3 minutes or pending for more than 3 minutes
    $staleStreams = Stream::whereIn('status', ['active', 'pending'])
        ->where(function ($query) {
            $query->where(function ($q) {
                $q->where('status', 'active')
                  ->where('started_at', '<', now()->subMinutes(3));
            })->orWhere(function ($q) {
                $q->where('status', 'pending')
                  ->where('created_at', '<', now()->subMinutes(3));
            });
        })
        ->get();

    foreach ($staleStreams as $stream) {
        $stream->update(['status' => 'ended', 'ended_at' => now()]);
        broadcast(new StreamEnded($stream->id, 'stale'));
        $this->info("Ended stale stream #{$stream->id}.");
    }

    if ($staleStreams->isEmpty()) {
        $this->info('No stale streams found.');
    }

    // Purge ended streams older than 24 hours
    $deleted = Stream::where('status', 'ended')
        ->where('ended_at', '<', now()->subHours(24))
        ->delete();

    if ($deleted > 0) {
        $this->info("Purged {$deleted} ended stream(s) older than 24 hours.");
    }
}
```

Also add the import at the top of the file:

```php
use App\Events\StreamEnded;
```

- [ ] **Step 4: Run all stale stream tests to verify they pass**

```bash
docker compose exec -T app php artisan test --filter="StaleStreamCleanupTest"
```

Expected: 4 tests pass.

- [ ] **Step 5: Commit**

```bash
git add app/Console/Commands/CheckStaleDevicesCommand.php tests/Feature/StaleStreamCleanupTest.php
git commit -m "feat: broadcast StreamEnded from stale cleanup, remove temp file I/O"
```

---

### Task 8: Remove MJPEG Route and Nginx Location Block

**Files:**
- Modify: `routes/web.php`
- Modify: `docker/nginx/default.conf`

- [ ] **Step 1: Remove the GET /stream/{stream} route**

In `routes/web.php`, remove this line:

```php
Route::get('/stream/{stream}', [\App\Http\Controllers\StreamFeedController::class, 'view']);
```

- [ ] **Step 2: Remove the browser stream viewer nginx location block**

In `docker/nginx/default.conf`, remove this entire block:

```nginx
# Browser stream viewer — disable buffering for real-time streaming
location ~ ^/stream/\d+$ {
    fastcgi_pass app:9000;
    fastcgi_index index.php;
    fastcgi_param SCRIPT_FILENAME $realpath_root/index.php;
    include fastcgi_params;
    fastcgi_buffering off;
    fastcgi_read_timeout 130s;
}
```

- [ ] **Step 3: Remove the view-related tests from StreamFeedTest**

The `test_view_rejects_unauthenticated_user` and `test_view_returns_404_for_nonexistent_stream` tests were already removed in Task 5 when we rewrote the test file. Verify they're gone:

```bash
docker compose exec -T app php artisan test --filter="test_view"
```

Expected: No tests found (confirms they're removed).

- [ ] **Step 4: Run full test suite to verify nothing is broken**

```bash
docker compose exec -T app php artisan test
```

Expected: All tests pass (no test references the removed route).

- [ ] **Step 5: Commit**

```bash
git add routes/web.php docker/nginx/default.conf
git commit -m "chore: remove MJPEG route and nginx location block"
```

---

### Task 9: Rewrite StreamView Component to Use Echo

**Files:**
- Modify: `resources/js/Components/StreamView.tsx`

- [ ] **Step 1: Rewrite StreamView.tsx**

Replace `resources/js/Components/StreamView.tsx` with:

```tsx
import { useState, useEffect, useRef, useCallback } from 'react';
import axios from 'axios';

type StreamStatus = 'idle' | 'connecting' | 'streaming' | 'ended' | 'error';

export default function StreamView() {
    const [status, setStatus] = useState<StreamStatus>('idle');
    const [streamId, setStreamId] = useState<number | null>(null);
    const [frameSrc, setFrameSrc] = useState<string | null>(null);
    const [timeLeft, setTimeLeft] = useState(120);
    const timerRef = useRef<ReturnType<typeof setInterval> | null>(null);
    const streamIdRef = useRef<number | null>(null);

    // Keep ref in sync for cleanup functions
    useEffect(() => {
        streamIdRef.current = streamId;
    }, [streamId]);

    const clearTimer = () => {
        if (timerRef.current) {
            clearInterval(timerRef.current);
            timerRef.current = null;
        }
    };

    const stopStream = useCallback(async (id?: number) => {
        const activeId = id ?? streamIdRef.current;
        if (!activeId) return;

        // Unsubscribe from Echo channel
        window.Echo?.leave(`stream.${activeId}`);

        try {
            await axios.post(`/stream/${activeId}/stop`);
        } catch {
            // Stream may already be ended
        }

        setStatus('ended');
        setFrameSrc(null);
        clearTimer();

        // Show "Stream ended" for 3 seconds, then return to idle
        setTimeout(() => {
            setStatus('idle');
            setStreamId(null);
            setTimeLeft(120);
        }, 3000);
    }, []);

    const startStream = async () => {
        setStatus('connecting');
        setFrameSrc(null);

        try {
            const response = await axios.post('/stream/start');
            const id = response.data.stream_id;
            setStreamId(id);
            setTimeLeft(120);

            // Subscribe to stream channel via Echo
            window.Echo
                .private(`stream.${id}`)
                .listen('.StreamFrameReceived', (e: { frame: string }) => {
                    setFrameSrc(`data:image/jpeg;base64,${e.frame}`);
                    setStatus('streaming');
                })
                .listen('.StreamEnded', () => {
                    setStatus('ended');
                    setFrameSrc(null);
                    clearTimer();
                    window.Echo?.leave(`stream.${id}`);

                    setTimeout(() => {
                        setStatus('idle');
                        setStreamId(null);
                        setTimeLeft(120);
                    }, 3000);
                });

            // Set status to streaming (will show connecting until first frame)
            setStatus('connecting');

            // Countdown timer
            timerRef.current = setInterval(() => {
                setTimeLeft((prev) => {
                    if (prev <= 1) {
                        stopStream(id);
                        return 0;
                    }
                    return prev - 1;
                });
            }, 1000);
        } catch {
            setStatus('error');
        }
    };

    // Cleanup on unmount / navigate away
    useEffect(() => {
        const handleBeforeUnload = () => {
            if (streamIdRef.current) {
                navigator.sendBeacon(`/stream/${streamIdRef.current}/stop`);
            }
        };

        window.addEventListener('beforeunload', handleBeforeUnload);

        return () => {
            window.removeEventListener('beforeunload', handleBeforeUnload);
            if (streamIdRef.current) {
                window.Echo?.leave(`stream.${streamIdRef.current}`);
                stopStream();
            }
        };
    }, [stopStream]);

    const formatTime = (seconds: number) => {
        const m = Math.floor(seconds / 60);
        const s = seconds % 60;
        return `${m}:${s.toString().padStart(2, '0')}`;
    };

    if (status === 'idle') {
        return (
            <div className="flex flex-col items-center gap-6">
                <button
                    onClick={startStream}
                    className="flex h-40 w-40 items-center justify-center rounded-full bg-indigo-600 text-white shadow-lg transition-all duration-150 hover:bg-indigo-700 hover:shadow-xl active:scale-95 focus:outline-none focus:ring-4 focus:ring-indigo-400 focus:ring-offset-2"
                >
                    <span className="flex flex-col items-center gap-1">
                        <svg className="h-10 w-10" fill="none" stroke="currentColor" strokeWidth={1.5} viewBox="0 0 24 24">
                            <path strokeLinecap="round" strokeLinejoin="round" d="m15.75 10.5 4.72-4.72a.75.75 0 0 1 1.28.53v11.38a.75.75 0 0 1-1.28.53l-4.72-4.72M4.5 18.75h9a2.25 2.25 0 0 0 2.25-2.25v-9a2.25 2.25 0 0 0-2.25-2.25h-9A2.25 2.25 0 0 0 2.25 7.5v9a2.25 2.25 0 0 0 2.25 2.25Z" />
                        </svg>
                        <span className="text-sm font-semibold tracking-wide">LIVE VIEW</span>
                    </span>
                </button>
                <div className="flex h-72 w-full max-w-lg items-center justify-center rounded-2xl border-2 border-dashed border-gray-200 bg-gray-50 text-gray-400">
                    <span className="text-sm">Live feed will appear here</span>
                </div>
            </div>
        );
    }

    if (status === 'connecting') {
        return (
            <div className="flex flex-col items-center gap-6">
                <div className="flex h-72 w-full max-w-lg items-center justify-center rounded-2xl border border-gray-200 bg-gray-50">
                    <div className="flex flex-col items-center gap-3 text-gray-500">
                        <svg className="h-8 w-8 animate-spin text-indigo-500" viewBox="0 0 24 24" fill="none">
                            <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
                            <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z" />
                        </svg>
                        <span className="text-sm">Connecting to device...</span>
                    </div>
                </div>
            </div>
        );
    }

    if (status === 'error') {
        return (
            <div className="flex flex-col items-center gap-6">
                <button
                    onClick={startStream}
                    className="flex h-40 w-40 items-center justify-center rounded-full bg-indigo-600 text-white shadow-lg transition-all duration-150 hover:bg-indigo-700 hover:shadow-xl active:scale-95 focus:outline-none focus:ring-4 focus:ring-indigo-400 focus:ring-offset-2"
                >
                    <span className="flex flex-col items-center gap-1">
                        <svg className="h-10 w-10" fill="none" stroke="currentColor" strokeWidth={1.5} viewBox="0 0 24 24">
                            <path strokeLinecap="round" strokeLinejoin="round" d="m15.75 10.5 4.72-4.72a.75.75 0 0 1 1.28.53v11.38a.75.75 0 0 1-1.28.53l-4.72-4.72M4.5 18.75h9a2.25 2.25 0 0 0 2.25-2.25v-9a2.25 2.25 0 0 0-2.25-2.25h-9A2.25 2.25 0 0 0 2.25 7.5v9a2.25 2.25 0 0 0 2.25 2.25Z" />
                        </svg>
                        <span className="text-sm font-semibold tracking-wide">RETRY</span>
                    </span>
                </button>
                <div className="flex h-72 w-full max-w-lg items-center justify-center rounded-2xl border border-red-200 bg-red-50 text-red-500">
                    <span className="text-sm font-medium">Failed to start stream. Try again.</span>
                </div>
            </div>
        );
    }

    if (status === 'ended') {
        return (
            <div className="flex flex-col items-center gap-6">
                <div className="flex h-72 w-full max-w-lg items-center justify-center rounded-2xl border border-gray-200 bg-gray-50">
                    <div className="flex flex-col items-center gap-3 text-gray-500">
                        <svg className="h-8 w-8 text-gray-400" fill="none" stroke="currentColor" strokeWidth={1.5} viewBox="0 0 24 24">
                            <path strokeLinecap="round" strokeLinejoin="round" d="M9.75 9.75l4.5 4.5m0-4.5l-4.5 4.5M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                        <span className="text-sm font-medium">Stream ended</span>
                    </div>
                </div>
            </div>
        );
    }

    // Streaming
    return (
        <div className="flex flex-col items-center gap-4">
            <div className="w-full max-w-lg overflow-hidden rounded-2xl shadow-lg">
                {frameSrc ? (
                    <img
                        src={frameSrc}
                        alt="Live feed"
                        className="w-full bg-black"
                    />
                ) : (
                    <div className="flex h-72 w-full items-center justify-center bg-black">
                        <span className="text-sm text-gray-500">Waiting for first frame...</span>
                    </div>
                )}
                <div className="flex items-center justify-between bg-gray-800 px-4 py-2">
                    <div className="flex items-center gap-2">
                        <span className="h-2 w-2 animate-pulse rounded-full bg-red-500" />
                        <span className="text-xs font-medium text-red-400">LIVE</span>
                    </div>
                    <span className="text-xs text-gray-400">{formatTime(timeLeft)}</span>
                </div>
            </div>
            <button
                onClick={() => stopStream()}
                className="rounded-lg bg-red-600 px-6 py-2 text-sm font-semibold text-white shadow transition hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-red-400 focus:ring-offset-2"
            >
                Stop Stream
            </button>
        </div>
    );
}
```

- [ ] **Step 2: Verify the frontend compiles**

```bash
docker compose exec -T vite npx vite build 2>&1 | tail -5
```

Expected: Build succeeds with no TypeScript errors.

- [ ] **Step 3: Commit**

```bash
git add resources/js/Components/StreamView.tsx
git commit -m "feat: rewrite StreamView to use Echo WebSocket instead of MJPEG img tag"
```

---

### Task 10: Run Full Test Suite and Manual Verification

- [ ] **Step 1: Run the full test suite**

```bash
docker compose exec -T app php artisan test
```

Expected: All tests pass. No references to temp files, MJPEG, or the removed route.

- [ ] **Step 2: Rebuild and restart containers**

```bash
docker compose down && docker compose up -d --build
```

- [ ] **Step 3: Manual verification checklist**

1. Open the dashboard in the browser
2. Open browser DevTools → Network tab → filter by "WS" to see WebSocket connection to Reverb
3. Click "LIVE VIEW" — should show "Connecting to device..."
4. Once ESP32 starts sending frames, the image should update in real-time
5. Countdown timer should tick from 2:00
6. Click "Stop Stream" — should show "Stream ended" for 3 seconds, then return to idle
7. Start another stream, let it run for 2 minutes — should auto-stop and show "Stream ended"

- [ ] **Step 4: Final commit (if any fixups needed)**

```bash
git add -A
git commit -m "fix: address manual testing feedback"
```
