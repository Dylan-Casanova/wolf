# Servo Trigger (Garage Door) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a garage door trigger button that sends an MQTT command to the ESP32, actuates a servo, and confirms execution via WebSocket.

**Architecture:** User taps button → Laravel publishes MQTT command → ESP32 actuates servo → ESP32 publishes ack → Laravel MQTT listener broadcasts `ServoTriggered` event via Reverb → frontend shows confirmation. Stream is stopped before trigger and restarted after ack if it was active.

**Tech Stack:** Laravel 10, Inertia/React, Laravel Echo, PhpMqtt, Reverb, ESP32 Arduino (C++)

---

## File Structure

| Action | File | Responsibility |
|--------|------|----------------|
| Create | `app/Events/ServoTriggered.php` | Broadcast servo ack to frontend |
| Create | `app/Http/Controllers/GarageController.php` | Handle POST /garage/trigger |
| Create | `resources/js/Components/GarageButton.tsx` | Trigger button with state management |
| Create | `tests/Feature/ServoTriggeredTest.php` | Event broadcast tests |
| Create | `tests/Feature/GarageControllerTest.php` | Controller endpoint tests |
| Create | `tests/Feature/DeviceChannelAuthTest.php` | Channel authorization tests |
| Modify | `app/Contracts/DeviceInterface.php` | Add `triggerServo()` method |
| Modify | `app/Services/Device/Esp32MqttDevice.php` | Implement `triggerServo()` via MQTT |
| Modify | `app/Services/Device/MockDevice.php` | Implement `triggerServo()` stub |
| Modify | `app/Console/Commands/MqttListenCommand.php` | Subscribe to `wolf/+/servo` topic |
| Modify | `routes/web.php` | Add POST /garage/trigger route |
| Modify | `routes/channels.php` | Add `device.{deviceId}` channel auth |
| Modify | `resources/js/Pages/Dashboard.tsx` | Pass device_id, integrate GarageButton, coordinate stream |
| Modify | `resources/js/Components/StreamView.tsx` | Expose start/stop via ref for Dashboard coordination |
| **FIRMWARE** | `~/Documents/Arduino/wolf_esp32_cam_v1/servo.h` | Servo setup + trigger + ack publish |
| **FIRMWARE** | `~/Documents/Arduino/wolf_esp32_cam_v1/mqtt.h` | Handle `trigger_servo` action |
| **FIRMWARE** | `~/Documents/Arduino/wolf_esp32_cam_v1/wolf_esp32_cam_v1.ino` | Include servo.h, call servoSetup() |

---

### Task 1: ServoTriggered Event

**Files:**
- Create: `app/Events/ServoTriggered.php`
- Create: `tests/Feature/ServoTriggeredTest.php`

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/ServoTriggeredTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Events\ServoTriggered;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Tests\TestCase;

class ServoTriggeredTest extends TestCase
{
    public function test_it_implements_should_broadcast_now(): void
    {
        $event = new ServoTriggered(1);

        $this->assertInstanceOf(ShouldBroadcastNow::class, $event);
    }

    public function test_it_broadcasts_on_private_device_channel(): void
    {
        $event = new ServoTriggered(42);

        $channels = $event->broadcastOn();

        $this->assertCount(1, $channels);
        $this->assertInstanceOf(PrivateChannel::class, $channels[0]);
        $this->assertEquals('device.42', $channels[0]->name);
    }

    public function test_it_broadcasts_correct_payload(): void
    {
        $event = new ServoTriggered(1);

        $this->assertEquals(['status' => 'done'], $event->broadcastWith());
    }

    public function test_it_broadcasts_as_servo_triggered(): void
    {
        $event = new ServoTriggered(1);

        $this->assertEquals('ServoTriggered', $event->broadcastAs());
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `docker compose exec app php artisan test --filter=ServoTriggeredTest`
Expected: FAIL — class `ServoTriggered` not found

- [ ] **Step 3: Implement ServoTriggered event**

Create `app/Events/ServoTriggered.php`:

```php
<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ServoTriggered implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        private readonly int $deviceId,
    ) {}

    public function broadcastAs(): string
    {
        return 'ServoTriggered';
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel("device.{$this->deviceId}")];
    }

    public function broadcastWith(): array
    {
        return ['status' => 'done'];
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `docker compose exec app php artisan test --filter=ServoTriggeredTest`
Expected: 4 tests, 4 assertions, PASS

---

### Task 2: DeviceInterface — Add triggerServo

**Files:**
- Modify: `app/Contracts/DeviceInterface.php`
- Modify: `app/Services/Device/Esp32MqttDevice.php`
- Modify: `app/Services/Device/MockDevice.php`

- [ ] **Step 1: Add method to DeviceInterface**

Add to `app/Contracts/DeviceInterface.php` after the `ping()` method:

```php
public function triggerServo(Device $device): bool;
```

- [ ] **Step 2: Implement in MockDevice**

Add to `app/Services/Device/MockDevice.php`:

```php
public function triggerServo(Device $device): bool
{
    return true;
}
```

- [ ] **Step 3: Implement in Esp32MqttDevice**

Add to `app/Services/Device/Esp32MqttDevice.php` after `stopStream()`:

```php
public function triggerServo(Device $device): bool
{
    return $this->publish($device->commandTopic(), [
        'action' => 'trigger_servo',
    ]);
}
```

- [ ] **Step 4: Run full test suite to verify nothing broke**

Run: `docker compose exec app php artisan test`
Expected: All existing tests pass (the new interface method is satisfied by both implementations)

---

### Task 3: GarageController + Route

**Files:**
- Create: `app/Http/Controllers/GarageController.php`
- Create: `tests/Feature/GarageControllerTest.php`
- Modify: `routes/web.php`

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/GarageControllerTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Contracts\DeviceInterface;
use App\Models\Device;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class GarageControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_with_device_can_trigger(): void
    {
        $user = User::factory()->create();
        $device = Device::factory()->create(['user_id' => $user->id]);

        $mock = Mockery::mock(DeviceInterface::class);
        $mock->shouldReceive('triggerServo')
            ->once()
            ->with(Mockery::on(fn ($d) => $d->id === $device->id))
            ->andReturn(true);
        $this->app->instance(DeviceInterface::class, $mock);

        $response = $this->actingAs($user)->post('/garage/trigger');

        $response->assertOk();
        $response->assertJson(['message' => 'Command sent.']);
    }

    public function test_user_without_device_gets_422(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/garage/trigger');

        $response->assertStatus(422);
    }

    public function test_unauthenticated_gets_redirect(): void
    {
        $response = $this->post('/garage/trigger');

        $response->assertRedirect('/login');
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `docker compose exec app php artisan test --filter=GarageControllerTest`
Expected: FAIL — route not defined

- [ ] **Step 3: Create GarageController**

Create `app/Http/Controllers/GarageController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Contracts\DeviceInterface;
use Illuminate\Http\Request;

class GarageController extends Controller
{
    public function __construct(private DeviceInterface $device) {}

    public function trigger(Request $request)
    {
        $user = $request->user();
        $device = $user->devices()->first();

        if (! $device) {
            return response()->json(['message' => 'No device registered.'], 422);
        }

        $this->device->triggerServo($device);

        return response()->json(['message' => 'Command sent.']);
    }
}
```

- [ ] **Step 4: Add route**

Add to `routes/web.php` inside the `Route::middleware('auth')` group, after the streaming routes:

```php
// Garage
Route::post('/garage/trigger', [\App\Http\Controllers\GarageController::class, 'trigger']);
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `docker compose exec app php artisan test --filter=GarageControllerTest`
Expected: 3 tests, 3 assertions, PASS

---

### Task 4: Device Channel Authorization

**Files:**
- Create: `tests/Feature/DeviceChannelAuthTest.php`
- Modify: `routes/channels.php`

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/DeviceChannelAuthTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Tests\TestCase;

class DeviceChannelAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_device_owner_can_access_device_channel(): void
    {
        $user = User::factory()->create();
        $device = Device::factory()->create(['user_id' => $user->id]);

        $channels = Broadcast::getChannels();
        $channel = $channels['device.{deviceId}'] ?? null;
        $this->assertNotNull($channel, 'device.{deviceId} channel not registered');

        // Resolve the callback directly (NullBroadcaster in test env)
        $options = $channel->options;
        $callback = $options['callback'] ?? $channel->callback;
        $result = $callback($user, $device->id);

        $this->assertTrue($result);
    }

    public function test_non_owner_cannot_access_device_channel(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $device = Device::factory()->create(['user_id' => $owner->id]);

        $channels = Broadcast::getChannels();
        $channel = $channels['device.{deviceId}'];

        $options = $channel->options;
        $callback = $options['callback'] ?? $channel->callback;
        $result = $callback($other, $device->id);

        $this->assertFalse($result);
    }

    public function test_nonexistent_device_returns_false(): void
    {
        $user = User::factory()->create();

        $channels = Broadcast::getChannels();
        $channel = $channels['device.{deviceId}'];

        $options = $channel->options;
        $callback = $options['callback'] ?? $channel->callback;
        $result = $callback($user, 99999);

        $this->assertFalse($result);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `docker compose exec app php artisan test --filter=DeviceChannelAuthTest`
Expected: FAIL — channel `device.{deviceId}` not registered

- [ ] **Step 3: Add channel authorization**

Add to `routes/channels.php`:

```php
Broadcast::channel('device.{deviceId}', function ($user, $deviceId) {
    return $user->devices()->where('id', $deviceId)->exists();
});
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `docker compose exec app php artisan test --filter=DeviceChannelAuthTest`
Expected: 3 tests, 3 assertions, PASS

---

### Task 5: MQTT Listener — Subscribe to Servo Ack Topic

**Files:**
- Modify: `app/Console/Commands/MqttListenCommand.php`

- [ ] **Step 1: Read current MqttListenCommand**

Read `app/Console/Commands/MqttListenCommand.php` to understand the existing subscribe pattern.

- [ ] **Step 2: Add servo topic subscription**

In the `handle()` method, after the existing topic subscriptions, add a subscription to `wolf/+/servo`:

```php
$mqtt->subscribe('wolf/+/servo', function (string $topic, string $message) {
    $this->info("Servo ack on {$topic}: {$message}");

    // Extract device_id from topic: wolf/{deviceId}/servo
    $parts = explode('/', $topic);
    $mqttDeviceId = $parts[1] ?? null;

    if (! $mqttDeviceId) {
        $this->warn('Could not extract device ID from servo topic');
        return;
    }

    $device = \App\Models\Device::where('device_id', $mqttDeviceId)->first();

    if (! $device) {
        $this->warn("No device found for ID: {$mqttDeviceId}");
        return;
    }

    broadcast(new \App\Events\ServoTriggered($device->id));

    $this->info("Broadcast ServoTriggered for device {$device->id}");
}, 1);
```

- [ ] **Step 3: Run full test suite**

Run: `docker compose exec app php artisan test`
Expected: All tests pass

---

### Task 6: Dashboard — Pass Device ID as Prop

**Files:**
- Modify: `routes/web.php`
- Modify: `resources/js/Pages/Dashboard.tsx`

- [ ] **Step 1: Update dashboard route to pass device_id**

In `routes/web.php`, replace the dashboard route:

```php
Route::get('/dashboard', function () {
    $device = auth()->user()->devices()->first();
    return Inertia::render('Dashboard', [
        'deviceId' => $device?->id,
    ]);
})->name('dashboard');
```

- [ ] **Step 2: Update Dashboard page to receive deviceId prop**

Read the current `resources/js/Pages/Dashboard.tsx` first, then update it to accept and pass the `deviceId` prop. The component should pass `deviceId` to both `StreamView` and (in a later task) `GarageButton`.

Update `resources/js/Pages/Dashboard.tsx`:

```tsx
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import StreamView from '@/Components/StreamView';
import { Head } from '@inertiajs/react';

export default function Dashboard({ deviceId }: { deviceId: number | null }) {
    return (
        <AuthenticatedLayout>
            <Head title="Dashboard" />
            <div className="py-12">
                <div className="max-w-7xl mx-auto sm:px-6 lg:px-8">
                    <div className="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                        <div className="p-6 text-gray-900 flex flex-col items-center gap-4">
                            <StreamView />
                        </div>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
```

Note: We'll add GarageButton and stream coordination in Task 8 after GarageButton is built.

- [ ] **Step 3: Verify the app still works**

Run: `docker compose exec app php artisan test`
Expected: All tests pass

---

### Task 7: GarageButton Component

**Files:**
- Create: `resources/js/Components/GarageButton.tsx`

- [ ] **Step 1: Create GarageButton component**

Create `resources/js/Components/GarageButton.tsx`:

```tsx
import { useState, useEffect, useCallback } from 'react';
import axios from 'axios';

type GarageState = 'idle' | 'triggering' | 'triggered' | 'error';

interface GarageButtonProps {
    deviceId: number;
    onTriggerStart?: () => void;
    onTriggerComplete?: () => void;
}

export default function GarageButton({ deviceId, onTriggerStart, onTriggerComplete }: GarageButtonProps) {
    const [state, setState] = useState<GarageState>('idle');

    useEffect(() => {
        if (!deviceId) return;

        const channel = window.Echo?.private(`device.${deviceId}`);

        channel?.listen('.ServoTriggered', () => {
            setState('triggered');
            onTriggerComplete?.();

            setTimeout(() => setState('idle'), 3000);
        });

        return () => {
            window.Echo?.leave(`device.${deviceId}`);
        };
    }, [deviceId]);

    const trigger = useCallback(async () => {
        if (state === 'triggering') return;

        setState('triggering');
        onTriggerStart?.();

        try {
            await axios.post('/garage/trigger');
        } catch {
            setState('error');
            setTimeout(() => setState('idle'), 3000);
            return;
        }

        // 10-second timeout for ack
        const timeout = setTimeout(() => {
            setState('error');
            setTimeout(() => setState('idle'), 3000);
        }, 10000);

        // Clear timeout when ack arrives (via Echo listener above)
        const cleanup = () => clearTimeout(timeout);
        const channel = window.Echo?.private(`device.${deviceId}`);
        channel?.listen('.ServoTriggered', cleanup);

        return () => {
            clearTimeout(timeout);
        };
    }, [deviceId, state, onTriggerStart]);

    const label: Record<GarageState, string> = {
        idle: 'Open / Close Garage',
        triggering: 'Triggering garage...',
        triggered: 'Garage triggered ✓',
        error: 'Failed to trigger — try again',
    };

    const colors: Record<GarageState, string> = {
        idle: 'bg-blue-600 hover:bg-blue-700 text-white',
        triggering: 'bg-yellow-500 text-white cursor-wait',
        triggered: 'bg-green-600 text-white',
        error: 'bg-red-600 text-white',
    };

    return (
        <button
            onClick={trigger}
            disabled={state === 'triggering'}
            className={`w-full px-6 py-3 rounded-lg font-semibold transition-colors ${colors[state]} disabled:opacity-75`}
        >
            {label[state]}
        </button>
    );
}
```

- [ ] **Step 2: Verify app compiles**

Run: `docker compose exec app npm run build`
Expected: Build succeeds with no TypeScript errors

---

### Task 8: Dashboard Integration — Stream Coordination + GarageButton

**Files:**
- Modify: `resources/js/Components/StreamView.tsx`
- Modify: `resources/js/Pages/Dashboard.tsx`

- [ ] **Step 1: Expose startStream and stopStream from StreamView via useImperativeHandle**

Read the current `resources/js/Components/StreamView.tsx`. Add `forwardRef` and `useImperativeHandle` to expose `startStream()` and `stopStream()` methods:

At the top, update imports:

```tsx
import { useState, useEffect, useCallback, useRef, forwardRef, useImperativeHandle } from 'react';
```

Export the handle type:

```tsx
export interface StreamViewHandle {
    startStream: () => void;
    stopStream: () => void;
}
```

Wrap the component with `forwardRef`:

```tsx
const StreamView = forwardRef<StreamViewHandle>(function StreamView(_, ref) {
    // ... existing component body ...

    useImperativeHandle(ref, () => ({
        startStream,
        stopStream,
    }));

    // ... rest of component ...
});

export default StreamView;
```

- [ ] **Step 2: Update Dashboard to coordinate stream and garage**

Update `resources/js/Pages/Dashboard.tsx`:

```tsx
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import StreamView, { StreamViewHandle } from '@/Components/StreamView';
import GarageButton from '@/Components/GarageButton';
import { Head } from '@inertiajs/react';
import { useRef } from 'react';

export default function Dashboard({ deviceId }: { deviceId: number | null }) {
    const streamRef = useRef<StreamViewHandle>(null);
    const wasStreamingRef = useRef(false);

    const handleTriggerStart = () => {
        // If streaming, stop it first and remember we were streaming
        if (streamRef.current) {
            wasStreamingRef.current = true;
            streamRef.current.stopStream();
        }
    };

    const handleTriggerComplete = () => {
        // If we were streaming before trigger, restart
        if (wasStreamingRef.current && streamRef.current) {
            wasStreamingRef.current = false;
            streamRef.current.startStream();
        }
    };

    return (
        <AuthenticatedLayout>
            <Head title="Dashboard" />
            <div className="py-12">
                <div className="max-w-7xl mx-auto sm:px-6 lg:px-8">
                    <div className="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                        <div className="p-6 text-gray-900 flex flex-col items-center gap-4">
                            <StreamView ref={streamRef} />
                            {deviceId && (
                                <GarageButton
                                    deviceId={deviceId}
                                    onTriggerStart={handleTriggerStart}
                                    onTriggerComplete={handleTriggerComplete}
                                />
                            )}
                        </div>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
```

- [ ] **Step 3: Handle wasStreamingRef correctly**

The `handleTriggerStart` needs to check if the stream is actually active. Update the check to read stream status. Since StreamView manages its own state, we need to also expose `isStreaming` from the handle:

In `StreamView.tsx`, update the handle:

```tsx
export interface StreamViewHandle {
    startStream: () => void;
    stopStream: () => void;
    isStreaming: () => boolean;
}
```

And in `useImperativeHandle`:

```tsx
useImperativeHandle(ref, () => ({
    startStream,
    stopStream,
    isStreaming: () => status === 'streaming',
}));
```

Then in `Dashboard.tsx`, update `handleTriggerStart`:

```tsx
const handleTriggerStart = () => {
    if (streamRef.current?.isStreaming()) {
        wasStreamingRef.current = true;
        streamRef.current.stopStream();
    }
};
```

- [ ] **Step 4: Verify app compiles and tests pass**

Run: `docker compose exec app npm run build && docker compose exec app php artisan test`
Expected: Build succeeds, all tests pass

---

### Task 9: Firmware — Servo Module + MQTT Handler (REQUIRES USER PERMISSION)

> **⚠️ STOP: This task modifies firmware files at `~/Documents/Arduino/wolf_esp32_cam_v1/`. Get user permission before proceeding.**

**Files:**
- Create: `/Users/mr.casanova/Documents/Arduino/wolf_esp32_cam_v1/servo.h`
- Modify: `/Users/mr.casanova/Documents/Arduino/wolf_esp32_cam_v1/mqtt.h`
- Modify: `/Users/mr.casanova/Documents/Arduino/wolf_esp32_cam_v1/wolf_esp32_cam_v1.ino`

- [ ] **Step 1: Create servo.h**

Create `/Users/mr.casanova/Documents/Arduino/wolf_esp32_cam_v1/servo.h`:

```cpp
#ifndef WOLF_SERVO_H
#define WOLF_SERVO_H

#include <ESP32Servo.h>
#include <PubSubClient.h>
#include "config.h"

static Servo _garageServo;
static const int SERVO_PIN = 13;
static const int SERVO_REST = 0;
static const int SERVO_PRESS = 90;
static const unsigned long SERVO_HOLD_MS = 500;

// Forward declaration — defined in mqtt.h
extern PubSubClient _mqttClient;

void servoSetup() {
  _garageServo.attach(SERVO_PIN);
  _garageServo.write(SERVO_REST);
  Serial.println("[wolf] Servo initialized on GPIO 13");
}

void servoTrigger() {
  Serial.println("[wolf] Servo triggering...");

  _garageServo.write(SERVO_PRESS);
  delay(SERVO_HOLD_MS);
  _garageServo.write(SERVO_REST);

  Serial.println("[wolf] Servo returned to rest");

  // Publish ack
  WolfConfig& cfg = configGet();
  String topic = "wolf/" + cfg.deviceId + "/servo";
  _mqttClient.publish(topic.c_str(), "{\"status\":\"done\"}");

  Serial.println("[wolf] Servo ack published");
}

#endif
```

- [ ] **Step 2: Add trigger_servo handler to mqtt.h**

In `/Users/mr.casanova/Documents/Arduino/wolf_esp32_cam_v1/mqtt.h`, in the `_mqttCallback` function, add after the `stop_stream` handler (before the `else` block):

```cpp
  } else if (strcmp(action, "trigger_servo") == 0) {
    Serial.println("[wolf] Trigger servo command");
    servoTrigger();
```

Also add the forward declaration near the top of mqtt.h (after the existing forward declarations):

```cpp
// Forward declaration — defined in servo.h
void servoSetup();
void servoTrigger();
```

- [ ] **Step 3: Update main sketch to include servo.h and call servoSetup()**

Read `/Users/mr.casanova/Documents/Arduino/wolf_esp32_cam_v1/wolf_esp32_cam_v1.ino`.

Add `#include "servo.h"` after the other includes.

Add `servoSetup();` in `setup()` after the other initialization calls (after `mqttConnect()` or similar).

- [ ] **Step 4: Verify firmware compiles**

Compile via Arduino IDE or CLI. The ESP32Servo library must be installed:
- In Arduino IDE: Sketch → Include Library → Manage Libraries → search "ESP32Servo" → Install

---

### Task 10: Final Integration Test

- [ ] **Step 1: Run the full backend test suite**

Run: `docker compose exec app php artisan test`
Expected: All tests pass (existing + new ServoTriggered, GarageController, DeviceChannelAuth tests)

- [ ] **Step 2: Run frontend build**

Run: `docker compose exec app npm run build`
Expected: Build succeeds

- [ ] **Step 3: Manual end-to-end test checklist**

1. Log in, navigate to Dashboard
2. Verify "Open / Close Garage" button appears below StreamView
3. Click button without stream active → should show "Triggering garage..." → wait for ack or 10s timeout
4. Start stream, then click garage button → stream stops, "Triggering garage..." shows, ack received, stream auto-restarts
5. Test timeout: with ESP32 offline, click button → after 10s shows "Failed to trigger — try again"
