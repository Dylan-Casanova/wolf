# Live Video Streaming Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace photo capture with on-demand MJPEG live streaming from ESP32-CAM to the dashboard using MQTT control + HTTP data.

**Architecture:** MQTT sends start/stop commands to the ESP32. ESP32 opens an HTTP chunked POST pushing MJPEG frames to a PHP endpoint that writes to a temp file. A second PHP endpoint reads from that file and streams to the browser via `<img>` tag. Nginx is configured with buffering disabled for both endpoints.

**Tech Stack:** Laravel 11, PHP-FPM, Nginx, MQTT (PubSubClient), React/Inertia, Arduino ESP32-CAM

---

### Task 1: Stream Migration and Model

**Files:**
- Create: `database/migrations/2026_06_03_000001_create_streams_table.php`
- Create: `app/Models/Stream.php`
- Create: `tests/Feature/StreamModelTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/StreamModelTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\Stream;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StreamModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_stream_belongs_to_device_and_user(): void
    {
        $user = User::factory()->create();
        $device = Device::factory()->create(['user_id' => $user->id]);

        $stream = Stream::create([
            'device_id' => $device->id,
            'user_id' => $user->id,
            'status' => 'pending',
        ]);

        $this->assertEquals($device->id, $stream->device->id);
        $this->assertEquals($user->id, $stream->user->id);
    }

    public function test_stream_has_correct_statuses(): void
    {
        $stream = Stream::factory()->create(['status' => 'pending']);
        $this->assertEquals('pending', $stream->status);

        $stream->update(['status' => 'active', 'started_at' => now()]);
        $this->assertEquals('active', $stream->fresh()->status);

        $stream->update(['status' => 'ended', 'ended_at' => now()]);
        $this->assertEquals('ended', $stream->fresh()->status);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec app php artisan test --filter=StreamModelTest`
Expected: FAIL — `Stream` class not found

- [ ] **Step 3: Create the migration**

Create `database/migrations/2026_06_03_000001_create_streams_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('streams', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default('pending'); // pending, active, ended
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('streams');
    }
};
```

- [ ] **Step 4: Create the Stream model**

Create `app/Models/Stream.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Stream extends Model
{
    use HasFactory;

    protected $fillable = [
        'device_id',
        'user_id',
        'status',
        'started_at',
        'ended_at',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
```

- [ ] **Step 5: Create the Stream factory**

Create `database/factories/StreamFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\Device;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class StreamFactory extends Factory
{
    public function definition(): array
    {
        return [
            'device_id' => Device::factory(),
            'user_id' => User::factory(),
            'status' => 'pending',
        ];
    }
}
```

- [ ] **Step 6: Run migration**

Run: `docker compose exec app php artisan migrate`
Expected: `streams` table created

- [ ] **Step 7: Run tests to verify they pass**

Run: `docker compose exec app php artisan test --filter=StreamModelTest`
Expected: PASS

- [ ] **Step 8: Commit**

```
feat: add streams table and model
```

---

### Task 2: Stream Controller — Start and Stop

**Files:**
- Create: `app/Http/Controllers/StreamController.php`
- Modify: `app/Contracts/DeviceInterface.php`
- Modify: `app/Services/Device/Esp32MqttDevice.php`
- Modify: `app/Services/Device/MockDevice.php`
- Create: `tests/Feature/StreamControllerTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/StreamControllerTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Contracts\DeviceInterface;
use App\Models\Device;
use App\Models\Stream;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class StreamControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_start_stream(): void
    {
        $mock = Mockery::mock(DeviceInterface::class);
        $mock->shouldReceive('startStream')->once()->andReturn(true);
        $this->app->instance(DeviceInterface::class, $mock);

        $user = User::factory()->create();
        $device = Device::factory()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user)->postJson('/api/stream/start');

        $response->assertOk();
        $response->assertJsonStructure(['stream_id']);
        $this->assertDatabaseHas('streams', [
            'device_id' => $device->id,
            'user_id' => $user->id,
            'status' => 'pending',
        ]);
    }

    public function test_user_without_device_cannot_start_stream(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/stream/start');

        $response->assertStatus(422);
    }

    public function test_user_can_stop_stream(): void
    {
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

        $response = $this->actingAs($user)->postJson("/api/stream/{$stream->id}/stop");

        $response->assertOk();
        $this->assertEquals('ended', $stream->fresh()->status);
        $this->assertNotNull($stream->fresh()->ended_at);
    }

    public function test_unauthenticated_user_cannot_start_stream(): void
    {
        $response = $this->postJson('/api/stream/start');

        $response->assertUnauthorized();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec app php artisan test --filter=StreamControllerTest`
Expected: FAIL — `StreamController` not found

- [ ] **Step 3: Update DeviceInterface**

Replace the contents of `app/Contracts/DeviceInterface.php`:

```php
<?php

namespace App\Contracts;

use App\Models\Device;

interface DeviceInterface
{
    /**
     * Send a start_stream command to a device.
     */
    public function startStream(Device $device, int $streamId): bool;

    /**
     * Send a stop_stream command to a device.
     */
    public function stopStream(Device $device): bool;

    /**
     * Check if the MQTT broker is reachable.
     */
    public function ping(): bool;
}
```

- [ ] **Step 4: Update Esp32MqttDevice**

Replace the contents of `app/Services/Device/Esp32MqttDevice.php`:

```php
<?php

namespace App\Services\Device;

use App\Contracts\DeviceInterface;
use App\Models\Device;
use Illuminate\Support\Facades\Log;
use PhpMqtt\Client\ConnectionSettings;
use PhpMqtt\Client\MqttClient;

class Esp32MqttDevice implements DeviceInterface
{
    public function __construct(
        private string $host,
        private int $port,
        private ?string $username,
        private ?string $password,
    ) {}

    public function startStream(Device $device, int $streamId): bool
    {
        return $this->publish($device->commandTopic(), json_encode([
            'action' => 'start_stream',
            'stream_id' => $streamId,
        ]));
    }

    public function stopStream(Device $device): bool
    {
        return $this->publish($device->commandTopic(), json_encode([
            'action' => 'stop_stream',
        ]));
    }

    public function ping(): bool
    {
        try {
            $client = new MqttClient($this->host, $this->port, 'wolf-ping');
            $client->connect(null, true);
            $client->disconnect();

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function publish(string $topic, string $payload): bool
    {
        try {
            $client = new MqttClient($this->host, $this->port, 'wolf-server-'.uniqid());

            $connectionSettings = new ConnectionSettings;

            if ($this->username && $this->username !== 'null') {
                $connectionSettings = $connectionSettings
                    ->setUsername($this->username)
                    ->setPassword($this->password);
            }

            $client->connect($connectionSettings, true);
            $client->publish($topic, $payload, qualityOfService: 1);
            $client->disconnect();

            return true;
        } catch (\Throwable $e) {
            Log::error('MQTT publish failed', [
                'error' => $e->getMessage(),
                'topic' => $topic,
            ]);

            return false;
        }
    }
}
```

- [ ] **Step 5: Update MockDevice**

Replace the contents of `app/Services/Device/MockDevice.php`:

```php
<?php

namespace App\Services\Device;

use App\Contracts\DeviceInterface;
use App\Models\Device;

class MockDevice implements DeviceInterface
{
    public function startStream(Device $device, int $streamId): bool
    {
        return true;
    }

    public function stopStream(Device $device): bool
    {
        return true;
    }

    public function ping(): bool
    {
        return true;
    }
}
```

- [ ] **Step 6: Create the StreamController**

Create `app/Http/Controllers/StreamController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Contracts\DeviceInterface;
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
     * Stop an active stream — sends MQTT command, cleans up.
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

        // Clean up temp file
        $tempFile = "/tmp/wolf-streams/{$stream->id}";
        if (file_exists($tempFile)) {
            unlink($tempFile);
        }

        return response()->json(['message' => 'Stream stopped.']);
    }
}
```

- [ ] **Step 7: Register routes**

Add to `routes/api.php` inside the `auth:sanctum` middleware group:

```php
    // Streaming
    Route::post('/stream/start', [\App\Http\Controllers\StreamController::class, 'start']);
    Route::post('/stream/{stream}/stop', [\App\Http\Controllers\StreamController::class, 'stop']);
```

- [ ] **Step 8: Run tests to verify they pass**

Run: `docker compose exec app php artisan test --filter=StreamControllerTest`
Expected: PASS

- [ ] **Step 9: Commit**

```
feat: add stream controller with start/stop via MQTT
```

---

### Task 3: Stream Feed Endpoints (ESP32 writer + browser reader)

**Files:**
- Create: `app/Http/Controllers/StreamFeedController.php`
- Create: `tests/Feature/StreamFeedTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/StreamFeedTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\Stream;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StreamFeedTest extends TestCase
{
    use RefreshDatabase;

    public function test_feed_rejects_invalid_device_token(): void
    {
        $stream = Stream::factory()->create(['status' => 'pending']);

        $response = $this->postJson("/api/device/stream/{$stream->id}/feed", [], [
            'X-Device-Token' => 'wrong-token',
            'Content-Type' => 'multipart/x-mixed-replace; boundary=frame',
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

    public function test_view_rejects_unauthenticated_user(): void
    {
        $stream = Stream::factory()->create(['status' => 'active']);

        $response = $this->get("/api/stream/{$stream->id}");

        $response->assertRedirect('/login');
    }

    public function test_view_returns_404_for_nonexistent_stream(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/api/stream/99999');

        $response->assertNotFound();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec app php artisan test --filter=StreamFeedTest`
Expected: FAIL — routes not defined

- [ ] **Step 3: Create the StreamFeedController**

Create `app/Http/Controllers/StreamFeedController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Models\Stream;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StreamFeedController extends Controller
{
    /**
     * ESP32 pushes MJPEG frames here via HTTP chunked POST.
     * Writes frames to a temp file that the browser-facing endpoint reads.
     */
    public function feed(Request $request, Stream $stream)
    {
        // Verify device token
        $token = $request->header('X-Device-Token');
        if (! $token || ! $stream->device || ! $stream->device->verifyToken($token)) {
            abort(401, 'Invalid device token.');
        }

        abort_if($stream->status === 'ended', 409, 'Stream already ended.');

        // Mark stream as active
        $stream->update(['status' => 'active', 'started_at' => now()]);

        // Ensure temp directory exists
        $tempDir = '/tmp/wolf-streams';
        if (! is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }

        $tempFile = "{$tempDir}/{$stream->id}";
        $timeout = 120; // 2 minutes
        $startTime = time();

        // Read from php://input and write frames to temp file
        $input = fopen('php://input', 'rb');

        while (! feof($input) && (time() - $startTime) < $timeout) {
            $chunk = fread($input, 65536); // 64KB chunks
            if ($chunk === false || strlen($chunk) === 0) {
                usleep(10000); // 10ms
                continue;
            }
            file_put_contents($tempFile, $chunk);
        }

        fclose($input);

        // Clean up when ESP32 disconnects or timeout
        $stream->update(['status' => 'ended', 'ended_at' => now()]);
        if (file_exists($tempFile)) {
            unlink($tempFile);
        }

        return response()->json(['message' => 'Stream ended.']);
    }

    /**
     * Browser reads the MJPEG stream from here.
     * Reads frames from the temp file written by the ESP32-facing endpoint.
     */
    public function view(Request $request, Stream $stream)
    {
        abort_if(! in_array($stream->status, ['pending', 'active']), 404);

        $tempFile = "/tmp/wolf-streams/{$stream->id}";
        $timeout = 120; // 2 minutes
        $boundary = 'frame';

        return new StreamedResponse(function () use ($tempFile, $timeout, $boundary, $stream) {
            $startTime = time();
            $lastModified = 0;

            while ((time() - $startTime) < $timeout) {
                // Check if stream was stopped
                $stream->refresh();
                if ($stream->status === 'ended') {
                    break;
                }

                if (! file_exists($tempFile)) {
                    usleep(50000); // 50ms — wait for ESP32 to start writing
                    continue;
                }

                $currentModified = filemtime($tempFile);
                if ($currentModified > $lastModified) {
                    $lastModified = $currentModified;
                    clearstatcache(true, $tempFile);

                    $frame = file_get_contents($tempFile);
                    if ($frame !== false && strlen($frame) > 0) {
                        echo "--{$boundary}\r\n";
                        echo "Content-Type: image/jpeg\r\n";
                        echo "Content-Length: ".strlen($frame)."\r\n\r\n";
                        echo $frame;
                        echo "\r\n";

                        if (ob_get_level()) {
                            ob_flush();
                        }
                        flush();
                    }
                }

                usleep(50000); // 50ms — ~20 FPS max check rate
            }
        }, 200, [
            'Content-Type' => "multipart/x-mixed-replace; boundary=frame",
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }
}
```

- [ ] **Step 4: Register routes**

Add to `routes/api.php`:

After the `auth:sanctum` group, add the ESP32-facing endpoint (no session auth, uses device token):

```php
// ESP32 stream feed — authenticated by device token
Route::post('/device/stream/{stream}/feed', [\App\Http\Controllers\StreamFeedController::class, 'feed']);
```

Inside the `auth:sanctum` group, add the browser-facing endpoint:

```php
    // Stream viewer
    Route::get('/stream/{stream}', [\App\Http\Controllers\StreamFeedController::class, 'view']);
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `docker compose exec app php artisan test --filter=StreamFeedTest`
Expected: PASS

- [ ] **Step 6: Commit**

```
feat: add stream feed endpoints for ESP32 writer and browser reader
```

---

### Task 4: Nginx Streaming Configuration

**Files:**
- Modify: `docker/nginx/default.conf`

- [ ] **Step 1: Update Nginx config**

Replace the contents of `docker/nginx/default.conf`:

```nginx
server {
    listen 80;
    server_name localhost;
    root /var/www/public;
    index index.php;

    client_max_body_size 20M;

    # Gzip compression
    gzip on;
    gzip_types text/plain text/css application/json application/javascript text/xml application/xml text/javascript image/svg+xml;

    # ESP32 stream feed — disable buffering for real-time streaming
    location ~ ^/api/device/stream/\d+/feed$ {
        client_max_body_size 0;
        client_body_timeout 130s;
        proxy_request_buffering off;
        fastcgi_pass app:9000;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $realpath_root/index.php;
        include fastcgi_params;
        fastcgi_buffering off;
        fastcgi_read_timeout 130s;
    }

    # Browser stream viewer — disable buffering for real-time streaming
    location ~ ^/api/stream/\d+$ {
        fastcgi_pass app:9000;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $realpath_root/index.php;
        include fastcgi_params;
        fastcgi_buffering off;
        fastcgi_read_timeout 130s;
    }

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    # PHP-FPM
    location ~ \.php$ {
        fastcgi_pass app:9000;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_buffering off;
    }

    # Deny dotfiles (except .well-known)
    location ~ /\.(?!well-known).* {
        deny all;
    }
}
```

- [ ] **Step 2: Restart Nginx**

Run: `docker compose restart nginx`
Expected: Nginx restarts with new config

- [ ] **Step 3: Verify config loaded**

Run: `docker compose exec nginx nginx -t`
Expected: `syntax is ok` / `test is successful`

- [ ] **Step 4: Commit**

```
feat: configure Nginx for MJPEG stream proxying
```

---

### Task 5: Stale Stream Cleanup

**Files:**
- Modify: `app/Console/Commands/CheckStaleDevicesCommand.php`
- Create: `tests/Feature/StaleStreamCleanupTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/StaleStreamCleanupTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Stream;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StaleStreamCleanupTest extends TestCase
{
    use RefreshDatabase;

    public function test_stale_active_streams_are_ended(): void
    {
        $stale = Stream::factory()->create([
            'status' => 'active',
            'started_at' => now()->subMinutes(5),
        ]);

        $fresh = Stream::factory()->create([
            'status' => 'active',
            'started_at' => now()->subSeconds(30),
        ]);

        $this->artisan('devices:check-stale')->assertSuccessful();

        $this->assertEquals('ended', $stale->fresh()->status);
        $this->assertNotNull($stale->fresh()->ended_at);
        $this->assertEquals('active', $fresh->fresh()->status);
    }

    public function test_stale_pending_streams_are_ended(): void
    {
        $stale = Stream::factory()->create([
            'status' => 'pending',
            'created_at' => now()->subMinutes(5),
        ]);

        $this->artisan('devices:check-stale')->assertSuccessful();

        $this->assertEquals('ended', $stale->fresh()->status);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec app php artisan test --filter=StaleStreamCleanupTest`
Expected: FAIL — streams not cleaned up

- [ ] **Step 3: Add stream cleanup to CheckStaleDevicesCommand**

Add the following method and call to `app/Console/Commands/CheckStaleDevicesCommand.php`:

The full updated file:

```php
<?php

namespace App\Console\Commands;

use App\Events\DeviceStatusChanged;
use App\Models\Device;
use App\Models\Stream;
use Illuminate\Console\Command;

class CheckStaleDevicesCommand extends Command
{
    protected $signature = 'devices:check-stale';

    protected $description = 'Mark stale devices as offline and clean up stale streams';

    public function handle(): int
    {
        $this->cleanStaleDevices();
        $this->cleanStaleStreams();

        return self::SUCCESS;
    }

    private function cleanStaleDevices(): void
    {
        $staleDevices = Device::where('is_online', true)
            ->where(function ($query) {
                $query->where('last_seen_at', '<', now()->subMinutes(2))
                    ->orWhereNull('last_seen_at');
            })
            ->get();

        foreach ($staleDevices as $device) {
            $device->markOffline();
            DeviceStatusChanged::dispatch($device->fresh());
            $this->info("Marked {$device->device_id} as offline (stale).");
        }

        if ($staleDevices->isEmpty()) {
            $this->info('No stale devices found.');
        }
    }

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

            $tempFile = "/tmp/wolf-streams/{$stream->id}";
            if (file_exists($tempFile)) {
                unlink($tempFile);
            }

            $this->info("Ended stale stream #{$stream->id}.");
        }

        if ($staleStreams->isEmpty()) {
            $this->info('No stale streams found.');
        }
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `docker compose exec app php artisan test --filter=StaleStreamCleanupTest`
Expected: PASS

- [ ] **Step 5: Verify existing stale device tests still pass**

Run: `docker compose exec app php artisan test --filter=CheckStaleDevicesCommandTest`
Expected: PASS

- [ ] **Step 6: Commit**

```
feat: add stale stream cleanup to devices:check-stale command
```

---

### Task 6: Dashboard Frontend — StreamView Component

**Files:**
- Create: `resources/js/Components/StreamView.tsx`
- Modify: `resources/js/Pages/Dashboard.tsx`
- Modify: `resources/js/types/index.d.ts`

- [ ] **Step 1: Add StreamData type**

Add to `resources/js/types/index.d.ts` (after the existing types):

```typescript
export interface StreamData {
    stream_id: number;
}
```

- [ ] **Step 2: Create StreamView component**

Create `resources/js/Components/StreamView.tsx`:

```tsx
import { useState, useEffect, useRef, useCallback } from 'react';
import axios from 'axios';

type StreamStatus = 'idle' | 'connecting' | 'streaming' | 'error';

export default function StreamView() {
    const [status, setStatus] = useState<StreamStatus>('idle');
    const [streamId, setStreamId] = useState<number | null>(null);
    const [timeLeft, setTimeLeft] = useState(120);
    const timerRef = useRef<ReturnType<typeof setInterval> | null>(null);
    const streamIdRef = useRef<number | null>(null);

    // Keep ref in sync for cleanup functions
    useEffect(() => {
        streamIdRef.current = streamId;
    }, [streamId]);

    const stopStream = useCallback(async (id?: number) => {
        const activeId = id ?? streamIdRef.current;
        if (!activeId) return;

        try {
            await axios.post(`/api/stream/${activeId}/stop`);
        } catch {
            // Stream may already be ended
        }

        setStatus('idle');
        setStreamId(null);
        setTimeLeft(120);

        if (timerRef.current) {
            clearInterval(timerRef.current);
            timerRef.current = null;
        }
    }, []);

    const startStream = async () => {
        setStatus('connecting');

        try {
            const response = await axios.post('/api/stream/start');
            const id = response.data.stream_id;
            setStreamId(id);
            setStatus('streaming');
            setTimeLeft(120);

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
                navigator.sendBeacon(`/api/stream/${streamIdRef.current}/stop`);
            }
        };

        window.addEventListener('beforeunload', handleBeforeUnload);

        return () => {
            window.removeEventListener('beforeunload', handleBeforeUnload);
            if (streamIdRef.current) {
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

    // Streaming
    return (
        <div className="flex flex-col items-center gap-4">
            <div className="w-full max-w-lg overflow-hidden rounded-2xl shadow-lg">
                <img
                    src={`/api/stream/${streamId}`}
                    alt="Live feed"
                    className="w-full bg-black"
                />
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

- [ ] **Step 3: Update Dashboard.tsx**

Replace the contents of `resources/js/Pages/Dashboard.tsx`:

```tsx
import StreamView from '@/Components/StreamView';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head } from '@inertiajs/react';

export default function Dashboard() {
    return (
        <AuthenticatedLayout
            header={
                <h2 className="text-xl font-semibold leading-tight text-gray-800">
                    Dashboard
                </h2>
            }
        >
            <Head title="Dashboard" />

            <div className="py-12">
                <div className="mx-auto max-w-2xl px-4 sm:px-6 lg:px-8">
                    <div className="flex flex-col items-center gap-10">
                        <StreamView />
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
```

- [ ] **Step 4: Verify TypeScript compiles**

Run: `docker compose exec vite npx tsc --noEmit`
Expected: No errors

- [ ] **Step 5: Commit**

```
feat: add StreamView component and update dashboard
```

---

### Task 7: Remove Capture System

**Files:**
- Delete: `app/Http/Controllers/DeviceCaptureController.php`
- Delete: `app/Http/Controllers/CaptureHistoryController.php`
- Delete: `app/Http/Resources/CaptureResource.php`
- Delete: `app/Services/Device/CaptureService.php`
- Delete: `app/Events/CaptureReady.php`
- Delete: `app/Models/DeviceCapture.php`
- Delete: `database/factories/DeviceCaptureFactory.php` (if exists)
- Delete: `resources/js/Components/CaptureButton.tsx`
- Delete: `resources/js/Components/MediaDisplay.tsx`
- Delete: `resources/js/Pages/Captures/History.tsx`
- Delete: `tests/Feature/CaptureHistoryTest.php`
- Create: `database/migrations/2026_06_03_000002_drop_device_captures_table.php`
- Modify: `routes/web.php`
- Modify: `routes/api.php`
- Modify: `app/Models/User.php`
- Modify: `resources/js/types/index.d.ts`

- [ ] **Step 1: Create migration to drop device_captures table**

Create `database/migrations/2026_06_03_000002_drop_device_captures_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('device_captures');
    }

    public function down(): void
    {
        // Not reversible — captures are ephemeral now
    }
};
```

- [ ] **Step 2: Remove capture routes from web.php**

In `routes/web.php`, remove these lines:

```php
use App\Http\Controllers\CaptureHistoryController;
use App\Http\Controllers\DeviceCaptureController;
```

And remove:

```php
    // Capture history
    Route::get('/captures', [CaptureHistoryController::class, 'index'])->name('captures.index');

    // Inertia capture trigger — returns redirect with flash
    Route::post('/device/capture', [DeviceCaptureController::class, 'store'])
        ->middleware('throttle:device-capture')
        ->name('device.capture');
```

- [ ] **Step 3: Remove capture routes from api.php**

In `routes/api.php`, remove these lines:

```php
use App\Http\Controllers\DeviceCaptureController;
```

And remove from the `auth:sanctum` group:

```php
    // Device captures
    Route::post('/device/capture', [DeviceCaptureController::class, 'store'])->middleware('throttle:device-capture');
    Route::get('/device/captures', [DeviceCaptureController::class, 'index']);
```

And remove:

```php
// ESP32 callback — called by the board after capturing media.
// The board authenticates using the capture_id received via MQTT.
Route::post('/device/captures/{capture}/upload', [DeviceCaptureController::class, 'upload'])
    ->name('device.captures.upload');
```

- [ ] **Step 4: Remove captures relationship from User model**

In `app/Models/User.php`, remove:

```php
    public function captures(): HasMany
    {
        return $this->hasMany(DeviceCapture::class);
    }
```

And remove the `DeviceCapture` import if no longer needed.

- [ ] **Step 5: Remove CaptureData type from types/index.d.ts**

In `resources/js/types/index.d.ts`, remove:

```typescript
export interface CaptureData {
    id: number;
    trigger_source: string;
    media_type: 'image' | 'video';
    media_url: string | null;
    status: 'pending' | 'success' | 'failed';
    error_message: string | null;
    captured_at: string;
    device?: { name: string };
    user?: { name: string; email: string };
}

export interface PaginatedCaptures {
    data: CaptureData[];
    links: {
        first: string | null;
        last: string | null;
        prev: string | null;
        next: string | null;
    };
    meta: {
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
    };
}
```

Also remove `capture?: CaptureData;` from the `flash` property in `PageProps`.

- [ ] **Step 6: Delete capture files**

Delete the following files:
- `app/Http/Controllers/DeviceCaptureController.php`
- `app/Http/Controllers/CaptureHistoryController.php`
- `app/Http/Resources/CaptureResource.php`
- `app/Services/Device/CaptureService.php`
- `app/Events/CaptureReady.php`
- `app/Models/DeviceCapture.php`
- `database/factories/DeviceCaptureFactory.php`
- `resources/js/Components/CaptureButton.tsx`
- `resources/js/Components/MediaDisplay.tsx`
- `resources/js/Pages/Captures/History.tsx`
- `tests/Feature/CaptureHistoryTest.php`

- [ ] **Step 7: Run migration**

Run: `docker compose exec app php artisan migrate`
Expected: `device_captures` table dropped

- [ ] **Step 8: Verify TypeScript compiles**

Run: `docker compose exec vite npx tsc --noEmit`
Expected: No errors

- [ ] **Step 9: Commit**

```
refactor: remove capture system, replaced by live streaming
```

---

### Task 8: ESP32 Firmware — Streaming Support

**Files:**
- Modify: `~/Documents/arduino/wolf-esp32-cam/mqtt.h`
- Create: `~/Documents/arduino/wolf-esp32-cam/stream.h`
- Delete: `~/Documents/arduino/wolf-esp32-cam/upload.h`
- Modify: `~/Documents/arduino/wolf-esp32-cam/wolf-esp32-cam.ino`

- [ ] **Step 1: Create stream.h**

Create `~/Documents/arduino/wolf-esp32-cam/stream.h`:

```cpp
#ifndef WOLF_STREAM_H
#define WOLF_STREAM_H

#include <HTTPClient.h>
#include <WiFi.h>
#include "config.h"
#include "camera.h"
#include "led.h"

// Streaming state
static bool _streaming = false;
static int _streamId = 0;
static const unsigned long STREAM_TIMEOUT = 120000; // 2 minutes

// Start streaming MJPEG frames to the server
void streamStart(int streamId) {
  if (_streaming) {
    Serial.println("[wolf] Already streaming — ignoring start");
    return;
  }

  _streamId = streamId;
  _streaming = true;

  WolfConfig& cfg = configGet();
  String url = cfg.serverUrl + "/api/device/stream/" + String(streamId) + "/feed";

  Serial.printf("[wolf] Starting stream %d to %s\n", streamId, url.c_str());

  WiFiClient client;
  HTTPClient http;

  http.begin(client, url);
  http.setTimeout(130000); // slightly longer than stream timeout
  http.addHeader("Content-Type", "multipart/x-mixed-replace; boundary=frame");
  http.addHeader("X-Device-Token", cfg.deviceToken);
  http.addHeader("Transfer-Encoding", "chunked");

  // We need to manually handle the connection for streaming
  // Use a raw WiFiClient connection instead of HTTPClient for chunked POST
  if (!client.connect(cfg.serverUrl.substring(7).c_str(), 8000)) {
    // Parse host from serverUrl (strip http://)
    Serial.println("[wolf] Stream connection failed");
    _streaming = false;
    return;
  }

  // Parse host and port from serverUrl
  String host = cfg.serverUrl;
  host.replace("http://", "");
  host.replace("https://", "");
  int colonPos = host.indexOf(':');
  int port = 8000;
  String hostname = host;
  if (colonPos > 0) {
    port = host.substring(colonPos + 1).toInt();
    hostname = host.substring(0, colonPos);
  }

  WiFiClient streamClient;
  if (!streamClient.connect(hostname.c_str(), port)) {
    Serial.println("[wolf] Stream TCP connection failed");
    _streaming = false;
    return;
  }

  // Send HTTP headers manually
  String path = "/api/device/stream/" + String(streamId) + "/feed";
  streamClient.printf("POST %s HTTP/1.1\r\n", path.c_str());
  streamClient.printf("Host: %s:%d\r\n", hostname.c_str(), port);
  streamClient.printf("X-Device-Token: %s\r\n", cfg.deviceToken.c_str());
  streamClient.print("Content-Type: multipart/x-mixed-replace; boundary=frame\r\n");
  streamClient.print("Transfer-Encoding: chunked\r\n");
  streamClient.print("Connection: keep-alive\r\n");
  streamClient.print("\r\n");

  Serial.println("[wolf] Stream connected — sending frames");
  ledSolid();

  unsigned long streamStart = millis();

  while (_streaming && (millis() - streamStart) < STREAM_TIMEOUT) {
    if (!streamClient.connected()) {
      Serial.println("[wolf] Stream connection lost");
      break;
    }

    // Take a frame
    camera_fb_t* fb = esp_camera_fb_get();
    if (!fb) {
      Serial.println("[wolf] Frame capture failed");
      delay(100);
      continue;
    }

    // Send as multipart MJPEG chunk
    String header = "--frame\r\nContent-Type: image/jpeg\r\nContent-Length: " + String(fb->len) + "\r\n\r\n";

    // Send chunk size (hex) + data for chunked encoding
    String chunkSize = String(header.length() + fb->len + 2, HEX); // +2 for trailing \r\n
    streamClient.print(chunkSize + "\r\n");
    streamClient.print(header);
    streamClient.write(fb->buf, fb->len);
    streamClient.print("\r\n");
    streamClient.print("\r\n"); // end of chunk

    esp_camera_fb_return(fb);

    // Process MQTT between frames (so we can receive stop_stream)
    extern PubSubClient _mqttClient;
    _mqttClient.loop();

    // Small delay to target ~10 FPS
    delay(100);
  }

  // Send final chunk (0-length = end of chunked transfer)
  if (streamClient.connected()) {
    streamClient.print("0\r\n\r\n");
    streamClient.stop();
  }

  _streaming = false;
  _streamId = 0;

  Serial.println("[wolf] Stream ended");
  ledSolid();
}

void streamStop() {
  if (!_streaming) return;

  Serial.println("[wolf] Stop stream command received");
  _streaming = false;
}

bool isStreaming() {
  return _streaming;
}

#endif
```

- [ ] **Step 2: Update mqtt.h**

Replace the contents of `~/Documents/arduino/wolf-esp32-cam/mqtt.h`:

```cpp
#ifndef WOLF_MQTT_H
#define WOLF_MQTT_H

#include <PubSubClient.h>
#include <ArduinoJson.h>
#include <WiFi.h>
#include "config.h"
#include "camera.h"
#include "led.h"

static WiFiClient _wifiClient;
static PubSubClient _mqttClient(_wifiClient);

// Reconnect tracking
static unsigned long _mqttLastAttempt = 0;
static unsigned long _mqttBackoff = 1000; // start at 1s, max 30s

// Heartbeat tracking
static unsigned long _mqttLastHeartbeat = 0;
static const unsigned long HEARTBEAT_INTERVAL = 60000; // 1 minute

// Build topic strings from device ID
static String _commandTopic;
static String _statusTopic;

// Forward declaration — defined in stream.h
void streamStart(int streamId);
void streamStop();
bool isStreaming();

// ── MQTT message callback ────────────────────────────────────

void _mqttCallback(char* topic, byte* payload, unsigned int length) {
  Serial.printf("[wolf] MQTT message on %s (%d bytes)\n", topic, length);

  // Parse JSON payload
  JsonDocument doc;
  DeserializationError err = deserializeJson(doc, payload, length);
  if (err) {
    Serial.printf("[wolf] JSON parse error: %s\n", err.c_str());
    return;
  }

  const char* action = doc["action"];
  if (!action) {
    Serial.println("[wolf] Missing action");
    return;
  }

  if (strcmp(action, "start_stream") == 0) {
    int streamId = doc["stream_id"] | 0;
    if (streamId == 0) {
      Serial.println("[wolf] Missing stream_id");
      return;
    }
    Serial.printf("[wolf] Start stream command (id: %d)\n", streamId);
    streamStart(streamId);

  } else if (strcmp(action, "stop_stream") == 0) {
    Serial.println("[wolf] Stop stream command");
    streamStop();

  } else {
    Serial.printf("[wolf] Unknown action: %s\n", action);
  }
}

// ── Connect to MQTT broker ───────────────────────────────────

bool mqttConnect() {
  WolfConfig& cfg = configGet();

  // Build topic strings
  _commandTopic = "wolf/" + cfg.deviceId + "/command";
  _statusTopic  = "wolf/" + cfg.deviceId + "/status";

  String clientId = "wolf-" + cfg.deviceId;

  _mqttClient.setServer(cfg.mqttHost.c_str(), cfg.mqttPort);
  _mqttClient.setCallback(_mqttCallback);

  // PubSubClient default buffer is 256 bytes — increase for JSON payloads
  _mqttClient.setBufferSize(512);

  Serial.printf("[wolf] Connecting to MQTT %s:%d as %s\n",
    cfg.mqttHost.c_str(), cfg.mqttPort, clientId.c_str());

  // Connect with LWT: if we disconnect unexpectedly, broker publishes "offline"
  bool connected = _mqttClient.connect(
    clientId.c_str(),           // client ID
    nullptr,                     // username (none for now)
    nullptr,                     // password (none for now)
    _statusTopic.c_str(),       // will topic
    1,                           // will QoS
    true,                        // will retain
    "offline"                    // will message
  );

  if (!connected) {
    Serial.printf("[wolf] MQTT connect failed, rc=%d\n", _mqttClient.state());
    return false;
  }

  // Publish online status (retained)
  _mqttClient.publish(_statusTopic.c_str(), "online", true);
  Serial.println("[wolf] Published online status");

  // Subscribe to command topic (QoS 1)
  _mqttClient.subscribe(_commandTopic.c_str(), 1);
  Serial.printf("[wolf] Subscribed to %s\n", _commandTopic.c_str());

  // Reset backoff on successful connection
  _mqttBackoff = 2000;

  return true;
}

// ── Reconnect with exponential backoff ───────────────────────

void mqttReconnect() {
  if (_mqttClient.connected()) return;

  unsigned long now = millis();
  if (now - _mqttLastAttempt < _mqttBackoff) return;

  _mqttLastAttempt = now;
  ledFastBlink();

  Serial.printf("[wolf] MQTT reconnecting (backoff: %lums)\n", _mqttBackoff);

  if (mqttConnect()) {
    ledSolid();
  } else {
    // Exponential backoff: 2s, 4s, 8s, 16s, 30s max
    _mqttBackoff = min(_mqttBackoff * 2, (unsigned long)30000);
  }
}

// ── Heartbeat ────────────────────────────────────────────────

void mqttHeartbeat() {
  if (!_mqttClient.connected()) return;

  // Skip heartbeat during streaming — active stream proves device is alive
  if (isStreaming()) return;

  unsigned long now = millis();
  if (now - _mqttLastHeartbeat < HEARTBEAT_INTERVAL) return;

  _mqttLastHeartbeat = now;

  String topic = "wolf/" + configGet().deviceId + "/heartbeat";
  char payload[128];
  snprintf(payload, sizeof(payload),
    "{\"rssi\":%d,\"free_heap\":%u,\"uptime\":%lu}",
    WiFi.RSSI(), ESP.getFreeHeap(), millis() / 1000);

  _mqttClient.publish(topic.c_str(), payload);
  Serial.printf("[wolf] Heartbeat sent: %s\n", payload);
}

// ── Call in loop() ───────────────────────────────────────────

void mqttLoop() {
  if (!_mqttClient.connected()) {
    mqttReconnect();
  }
  _mqttClient.loop();
  mqttHeartbeat();
}

#endif
```

- [ ] **Step 3: Update wolf-esp32-cam.ino**

Replace the `#include` section at the top of `wolf-esp32-cam.ino`:

Change:
```cpp
#include "config.h"
#include "camera.h"
#include "upload.h"
#include "mqtt.h"
```

To:
```cpp
#include "config.h"
#include "camera.h"
#include "stream.h"
#include "mqtt.h"
```

- [ ] **Step 4: Delete upload.h**

Delete `~/Documents/arduino/wolf-esp32-cam/upload.h`

- [ ] **Step 5: Verify firmware compiles**

Open the Arduino IDE and verify/compile the sketch. Expected: no errors.

- [ ] **Step 6: Flash and test**

Flash the firmware to the ESP32. Serial monitor should show:
```
[wolf] Ready — waiting for capture commands
```

When "Live View" is clicked on the dashboard, serial monitor should show:
```
[wolf] Start stream command (id: X)
[wolf] Stream connected — sending frames
```

- [ ] **Step 7: Commit firmware changes**

```
feat: replace photo capture with MJPEG streaming in ESP32 firmware
```

---

### Task 9: Run Full Test Suite

- [ ] **Step 1: Run all tests**

Run: `docker compose exec app php artisan test`
Expected: All tests pass (capture-related tests should be deleted by now)

- [ ] **Step 2: Verify TypeScript compiles**

Run: `docker compose exec vite npx tsc --noEmit`
Expected: No errors

- [ ] **Step 3: Fix any failures**

If any test fails, investigate and fix before proceeding.

- [ ] **Step 4: Commit if any fixes were needed**

```
fix: resolve test failures from streaming migration
```

---

### Task 10: End-to-End Verification

- [ ] **Step 1: Restart all Docker services**

Run: `docker compose down && docker compose up -d`
Expected: All services start including mqtt-listener

- [ ] **Step 2: Test stream from dashboard**

1. Open browser to `http://localhost:8000/dashboard`
2. Click "LIVE VIEW" button
3. Verify: button changes to connecting state
4. Verify: video feed appears in the `<img>` tag
5. Verify: "LIVE" indicator and countdown timer shown
6. Verify: "Stop Stream" button is visible

- [ ] **Step 3: Test stop stream**

1. Click "Stop Stream"
2. Verify: feed stops, button resets to "LIVE VIEW"
3. Verify: stream record in database has status "ended"

- [ ] **Step 4: Test auto-timeout**

1. Start a stream
2. Wait 2 minutes
3. Verify: stream stops automatically
4. Verify: temp file cleaned up

- [ ] **Step 5: Test navigate away cleanup**

1. Start a stream
2. Navigate to another page (e.g., /devices)
3. Verify: stream is stopped (check database or ESP32 serial monitor)
