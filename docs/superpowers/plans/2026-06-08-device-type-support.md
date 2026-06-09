# Device Type Support Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make Wolf device-type-aware so ESP8266 (servo-only) and ESP32-CAM (servo + streaming) devices are supported with the correct UI for each.

**Architecture:** Add a `DeviceType` enum with `esp32_cam` and `esp8266` values. The enum drives validation, the admin device form dropdown, and the dashboard layout — ESP32-CAM users see StreamView + GarageButton, ESP8266 users see only GarageButton. No changes to MQTT, DeviceInterface, or broadcast infrastructure.

**Tech Stack:** Laravel 10, PHP 8.2 enums, Inertia/React, TypeScript

---

## File Structure

| Action | File | Responsibility |
|--------|------|----------------|
| Create | `app/Enums/DeviceType.php` | Enum with `esp32_cam`, `esp8266` cases + labels |
| Modify | `app/Models/Device.php` | Cast `type` to `DeviceType` enum |
| Modify | `app/Http/Requests/StoreDeviceRequest.php` | Validate type against enum values |
| Modify | `app/Http/Requests/UpdateDeviceRequest.php` | Validate type against enum values |
| Modify | `app/Http/Controllers/DeviceController.php` | Pass device types to create/edit views |
| Modify | `resources/js/Pages/Devices/Create.tsx` | Replace TextInput with dropdown |
| Modify | `resources/js/Pages/Devices/Edit.tsx` | Replace TextInput with dropdown |
| Modify | `resources/js/types/index.d.ts` | Type `Device.type` as union |
| Modify | `routes/web.php` | Pass `deviceType` to Dashboard |
| Modify | `resources/js/Pages/Dashboard.tsx` | Conditionally render StreamView |
| Modify | `database/factories/DeviceFactory.php` | Add `esp8266()` state |
| Create | `tests/Feature/DeviceTypeTest.php` | Enum + dashboard conditional tests |

---

### Task 1: DeviceType Enum

**Files:**
- Create: `app/Enums/DeviceType.php`
- Create: `tests/Feature/DeviceTypeTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/DeviceTypeTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Enums\DeviceType;
use Tests\TestCase;

class DeviceTypeTest extends TestCase
{
    public function test_enum_has_expected_cases(): void
    {
        $cases = array_map(fn ($case) => $case->value, DeviceType::cases());

        $this->assertEquals(['esp32_cam', 'esp8266'], $cases);
    }

    public function test_enum_has_labels(): void
    {
        $this->assertEquals('ESP32-CAM', DeviceType::Esp32Cam->label());
        $this->assertEquals('ESP8266', DeviceType::Esp8266->label());
    }

    public function test_enum_has_values_array(): void
    {
        $values = DeviceType::values();

        $this->assertEquals(['esp32_cam', 'esp8266'], $values);
    }

    public function test_enum_has_options_for_forms(): void
    {
        $options = DeviceType::options();

        $this->assertEquals([
            ['value' => 'esp32_cam', 'label' => 'ESP32-CAM'],
            ['value' => 'esp8266', 'label' => 'ESP8266'],
        ], $options);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `docker compose exec app php artisan test --filter=DeviceTypeTest`
Expected: FAIL — class `DeviceType` not found

- [ ] **Step 3: Create the DeviceType enum**

Create `app/Enums/DeviceType.php`:

```php
<?php

namespace App\Enums;

enum DeviceType: string
{
    case Esp32Cam = 'esp32_cam';
    case Esp8266 = 'esp8266';

    public function label(): string
    {
        return match ($this) {
            self::Esp32Cam => 'ESP32-CAM',
            self::Esp8266 => 'ESP8266',
        };
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public static function options(): array
    {
        return array_map(fn (self $case) => [
            'value' => $case->value,
            'label' => $case->label(),
        ], self::cases());
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `docker compose exec app php artisan test --filter=DeviceTypeTest`
Expected: 4 tests, 4 assertions, PASS

---

### Task 2: Device Model — Cast Type to Enum

**Files:**
- Modify: `app/Models/Device.php`

- [ ] **Step 1: Add the enum cast**

In `app/Models/Device.php`, add `DeviceType` to the `$casts` array. The current casts are:

```php
protected $casts = [
    'is_online' => 'boolean',
    'last_seen_at' => 'datetime',
    'meta' => 'array',
];
```

Update to:

```php
protected $casts = [
    'is_online' => 'boolean',
    'last_seen_at' => 'datetime',
    'meta' => 'array',
    'type' => \App\Enums\DeviceType::class,
];
```

- [ ] **Step 2: Run full test suite**

Run: `docker compose exec app php artisan test`
Expected: All tests pass. The factory already sets `type => 'esp32-cam'` — this will cause a failure because the enum expects `esp32_cam` (underscore, not hyphen). If tests fail, proceed to Task 3 which updates the factory.

---

### Task 3: Update Factory + Database Migration for Enum Values

**Files:**
- Modify: `database/factories/DeviceFactory.php`
- Create: `database/migrations/2026_06_08_000000_normalize_device_type_values.php`

- [ ] **Step 1: Update DeviceFactory**

In `database/factories/DeviceFactory.php`, update the `definition()` method. Change:

```php
'type' => 'esp32-cam',
```

To:

```php
'type' => \App\Enums\DeviceType::Esp32Cam->value,
```

Add an `esp8266()` state method after the existing `online()` method:

```php
public function esp8266(): static
{
    return $this->state(fn (array $attributes) => [
        'type' => \App\Enums\DeviceType::Esp8266->value,
    ]);
}
```

- [ ] **Step 2: Create migration to normalize existing data**

Existing devices in the database may have `type = 'esp32-cam'` (hyphen). The enum uses `esp32_cam` (underscore). Create a migration to normalize:

Run: `docker compose exec app php artisan make:migration normalize_device_type_values`

Then replace the migration content with:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('devices')
            ->where('type', 'esp32-cam')
            ->update(['type' => 'esp32_cam']);
    }

    public function down(): void
    {
        DB::table('devices')
            ->where('type', 'esp32_cam')
            ->update(['type' => 'esp32-cam']);
    }
};
```

- [ ] **Step 3: Run migration**

Run: `docker compose exec app php artisan migrate`

- [ ] **Step 4: Run full test suite**

Run: `docker compose exec app php artisan test`
Expected: All tests pass now that factory uses enum values

---

### Task 4: Validation — Restrict Type to Enum Values

**Files:**
- Modify: `app/Http/Requests/StoreDeviceRequest.php`
- Modify: `app/Http/Requests/UpdateDeviceRequest.php`
- Add tests to: `tests/Feature/DeviceTypeTest.php`

- [ ] **Step 1: Add validation tests**

Append to `tests/Feature/DeviceTypeTest.php`:

```php
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

// Add RefreshDatabase trait to the class
```

Then add these test methods:

```php
use RefreshDatabase;

public function test_store_device_validates_type_against_enum(): void
{
    $admin = User::factory()->create(['is_admin' => true]);
    $user = User::factory()->create();

    $response = $this->actingAs($admin)->post(route('devices.store'), [
        'name' => 'Test Device',
        'device_id' => 'test-001',
        'user_id' => $user->id,
        'type' => 'invalid_type',
    ]);

    $response->assertSessionHasErrors('type');
}

public function test_store_device_accepts_valid_enum_type(): void
{
    $admin = User::factory()->create(['is_admin' => true]);
    $user = User::factory()->create();

    $response = $this->actingAs($admin)->post(route('devices.store'), [
        'name' => 'Test Device',
        'device_id' => 'test-001',
        'user_id' => $user->id,
        'type' => 'esp8266',
    ]);

    $response->assertSessionHasNoErrors();
    $this->assertDatabaseHas('devices', ['device_id' => 'test-001', 'type' => 'esp8266']);
}
```

- [ ] **Step 2: Run tests to verify the invalid type test fails**

Run: `docker compose exec app php artisan test --filter=test_store_device_validates_type_against_enum`
Expected: FAIL — validation currently accepts any string

- [ ] **Step 3: Update StoreDeviceRequest**

In `app/Http/Requests/StoreDeviceRequest.php`, add the import at the top:

```php
use App\Enums\DeviceType;
use Illuminate\Validation\Rule;
```

Update the `type` rule from:

```php
'type' => ['sometimes', 'string', 'max:255'],
```

To:

```php
'type' => ['required', Rule::in(DeviceType::values())],
```

- [ ] **Step 4: Update UpdateDeviceRequest**

In `app/Http/Requests/UpdateDeviceRequest.php`, add the same imports and update the `type` rule identically:

```php
use App\Enums\DeviceType;
use Illuminate\Validation\Rule;
```

```php
'type' => ['required', Rule::in(DeviceType::values())],
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `docker compose exec app php artisan test --filter=DeviceTypeTest`
Expected: All 6 tests pass

---

### Task 5: Admin UI — Device Type Dropdown

**Files:**
- Modify: `app/Http/Controllers/DeviceController.php`
- Modify: `resources/js/Pages/Devices/Create.tsx`
- Modify: `resources/js/Pages/Devices/Edit.tsx`
- Modify: `resources/js/types/index.d.ts`

- [ ] **Step 1: Update DeviceController to pass device types**

In `app/Http/Controllers/DeviceController.php`, add the import:

```php
use App\Enums\DeviceType;
```

Update the `create()` method to pass device types. Currently:

```php
public function create()
{
    $users = User::whereDoesntHave('devices')->get(['id', 'name', 'email']);

    return Inertia::render('Devices/Create', [
        'users' => $users,
    ]);
}
```

Update to:

```php
public function create()
{
    $users = User::whereDoesntHave('devices')->get(['id', 'name', 'email']);

    return Inertia::render('Devices/Create', [
        'users' => $users,
        'deviceTypes' => DeviceType::options(),
    ]);
}
```

Update the `edit()` method similarly. Find where it renders `Devices/Edit` and add `deviceTypes`:

```php
'deviceTypes' => DeviceType::options(),
```

- [ ] **Step 2: Update TypeScript types**

In `resources/js/types/index.d.ts`, change:

```typescript
type: string;
```

To:

```typescript
type: 'esp32_cam' | 'esp8266';
```

Add a new type for the dropdown options:

```typescript
export interface DeviceTypeOption {
    value: string;
    label: string;
}
```

- [ ] **Step 3: Update Create.tsx — replace TextInput with dropdown**

In `resources/js/Pages/Devices/Create.tsx`, update the props to receive `deviceTypes`:

```tsx
export default function Create({ users, deviceTypes }: PageProps<{
    users: Pick<User, 'id' | 'name' | 'email'>[];
    deviceTypes: { value: string; label: string }[];
}>) {
```

Update the `useForm` default type:

```tsx
type: 'esp32_cam',
```

Replace the Device Type field (lines 83–92) from TextInput to a select dropdown:

```tsx
<div>
    <InputLabel htmlFor="type" value="Device Type" />
    <select
        id="type"
        value={data.type}
        onChange={(e) => setData('type', e.target.value)}
        className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
        required
    >
        {deviceTypes.map((dt) => (
            <option key={dt.value} value={dt.value}>
                {dt.label}
            </option>
        ))}
    </select>
    <InputError message={errors.type} className="mt-2" />
</div>
```

- [ ] **Step 4: Update Edit.tsx — replace TextInput with dropdown**

In `resources/js/Pages/Devices/Edit.tsx`, update the props:

```tsx
export default function Edit({
    device,
    users,
    deviceTypes,
}: PageProps<{
    device: Device;
    users: Pick<User, 'id' | 'name' | 'email'>[];
    deviceTypes: { value: string; label: string }[];
}>) {
```

Replace the Device Type field (lines 106–115) with the same select dropdown as Create.tsx:

```tsx
<div>
    <InputLabel htmlFor="type" value="Device Type" />
    <select
        id="type"
        value={data.type}
        onChange={(e) => setData('type', e.target.value)}
        className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
        required
    >
        {deviceTypes.map((dt) => (
            <option key={dt.value} value={dt.value}>
                {dt.label}
            </option>
        ))}
    </select>
    <InputError message={errors.type} className="mt-2" />
</div>
```

- [ ] **Step 5: Verify frontend builds and tests pass**

Run: `docker compose exec app npm run build && docker compose exec app php artisan test`
Expected: Build succeeds, all tests pass

---

### Task 6: Dashboard — Conditional StreamView Based on Device Type

**Files:**
- Modify: `routes/web.php`
- Modify: `resources/js/Pages/Dashboard.tsx`
- Add tests to: `tests/Feature/DeviceTypeTest.php`

- [ ] **Step 1: Add dashboard test**

Append to `tests/Feature/DeviceTypeTest.php`:

```php
public function test_dashboard_passes_device_type(): void
{
    $user = User::factory()->create();
    \App\Models\Device::factory()->esp8266()->create(['user_id' => $user->id]);

    $response = $this->actingAs($user)->get('/dashboard');

    $response->assertInertia(fn ($page) => $page
        ->component('Dashboard')
        ->has('deviceType')
        ->where('deviceType', 'esp8266')
    );
}

public function test_dashboard_passes_null_device_type_without_device(): void
{
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get('/dashboard');

    $response->assertInertia(fn ($page) => $page
        ->component('Dashboard')
        ->where('deviceType', null)
    );
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `docker compose exec app php artisan test --filter=test_dashboard_passes_device_type`
Expected: FAIL — `deviceType` not in response

- [ ] **Step 3: Update dashboard route**

In `routes/web.php`, update the dashboard route from:

```php
Route::get('/dashboard', function () {
    $device = auth()->user()->devices()->first();

    return Inertia::render('Dashboard', [
        'deviceId' => $device?->id,
    ]);
})->name('dashboard');
```

To:

```php
Route::get('/dashboard', function () {
    $device = auth()->user()->devices()->first();

    return Inertia::render('Dashboard', [
        'deviceId' => $device?->id,
        'deviceType' => $device?->type?->value,
    ]);
})->name('dashboard');
```

- [ ] **Step 4: Update Dashboard.tsx**

Update `resources/js/Pages/Dashboard.tsx`:

```tsx
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import StreamView, { StreamViewHandle } from '@/Components/StreamView';
import GarageButton from '@/Components/GarageButton';
import { Head } from '@inertiajs/react';
import { useRef } from 'react';

interface DashboardProps {
    deviceId: number | null;
    deviceType: 'esp32_cam' | 'esp8266' | null;
}

export default function Dashboard({ deviceId, deviceType }: DashboardProps) {
    const streamRef = useRef<StreamViewHandle>(null);
    const wasStreamingRef = useRef(false);
    const hasCamera = deviceType === 'esp32_cam';

    const handleTriggerStart = () => {
        if (hasCamera && streamRef.current?.isStreaming()) {
            wasStreamingRef.current = true;
            streamRef.current.stopStream();
        }
    };

    const handleTriggerComplete = () => {
        if (hasCamera && wasStreamingRef.current && streamRef.current) {
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
                            {hasCamera && <StreamView ref={streamRef} />}
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

- [ ] **Step 5: Run tests and build**

Run: `docker compose exec app php artisan test --filter=DeviceTypeTest && docker compose exec app npm run build`
Expected: All tests pass, build succeeds

---

### Task 7: ESP8266 Firmware Skeleton (REQUIRES USER PERMISSION)

> **⚠️ STOP: This task creates firmware files at `~/Documents/Arduino/`. Get user permission before proceeding.**

**Files:**
- Create: `/Users/mr.casanova/Documents/Arduino/wolf_esp8266_v1/wolf_esp8266_v1.ino`
- Create: `/Users/mr.casanova/Documents/Arduino/wolf_esp8266_v1/config.h`
- Create: `/Users/mr.casanova/Documents/Arduino/wolf_esp8266_v1/mqtt.h`
- Create: `/Users/mr.casanova/Documents/Arduino/wolf_esp8266_v1/servo.h`
- Create: `/Users/mr.casanova/Documents/Arduino/wolf_esp8266_v1/led.h`

- [ ] **Step 1: Create the project directory**

Run: `mkdir -p /Users/mr.casanova/Documents/Arduino/wolf_esp8266_v1`

- [ ] **Step 2: Create config.h**

This is adapted from the ESP32-CAM version but uses ESP8266-specific libraries and NVS via `EEPROM` or `LittleFS`. Read `/Users/mr.casanova/Documents/Arduino/wolf_esp32_cam_v1/config.h` first to understand the structure, then create an ESP8266-compatible version.

Key differences from ESP32-CAM config.h:
- Use `ESP8266WiFi.h` instead of `WiFi.h`
- Use `ESP8266WebServer.h` instead of `WebServer.h`
- Use `EEPROM.h` or `LittleFS.h` for persistent config (instead of `Preferences.h`)
- Use `ESP8266HTTPClient.h` instead of `HTTPClient.h`
- Same `WolfConfig` struct (serverUrl, deviceId, deviceToken, mqttHost, mqttPort, ssid, password)
- Same captive portal logic for initial setup
- Same provisioning flow: connect WiFi → fetch config from server → save to flash

- [ ] **Step 3: Create led.h**

Adapted from ESP32-CAM. The NodeMCU has a built-in LED on GPIO 2 (inverted logic — LOW = on, HIGH = off).

```cpp
#ifndef WOLF_LED_H
#define WOLF_LED_H

static const int LED_PIN = 2; // NodeMCU built-in LED (inverted)
static unsigned long _ledLastToggle = 0;
static bool _ledState = false;

typedef enum { LED_OFF, LED_SOLID, LED_SLOW_BLINK, LED_FAST_BLINK } LedMode;
static LedMode _ledMode = LED_OFF;

void ledInit() {
  pinMode(LED_PIN, OUTPUT);
  digitalWrite(LED_PIN, HIGH); // OFF (inverted)
}

void ledSolid() { _ledMode = LED_SOLID; digitalWrite(LED_PIN, LOW); }
void ledSlowBlink() { _ledMode = LED_SLOW_BLINK; }
void ledFastBlink() { _ledMode = LED_FAST_BLINK; }
void ledOff() { _ledMode = LED_OFF; digitalWrite(LED_PIN, HIGH); }

void ledUpdate() {
  if (_ledMode == LED_SOLID || _ledMode == LED_OFF) return;

  unsigned long interval = (_ledMode == LED_FAST_BLINK) ? 150 : 500;
  unsigned long now = millis();

  if (now - _ledLastToggle >= interval) {
    _ledLastToggle = now;
    _ledState = !_ledState;
    digitalWrite(LED_PIN, _ledState ? LOW : HIGH);
  }
}

#endif
```

- [ ] **Step 4: Create servo.h**

The ESP8266 uses the standard Arduino `Servo.h` library (not ESP32Servo):

```cpp
#ifndef WOLF_SERVO_H
#define WOLF_SERVO_H

#include <Servo.h>
#include <PubSubClient.h>
#include "config.h"

static Servo _garageServo;
static const int SERVO_PIN = D4; // GPIO 2 conflicts with LED, use D4 (GPIO 2) or D5 (GPIO 14)
static const int SERVO_REST = 0;
static const int SERVO_PRESS = 90;
static const unsigned long SERVO_HOLD_MS = 500;

// Forward declaration — defined in mqtt.h
extern PubSubClient _mqttClient;

void servoSetup() {
  _garageServo.attach(SERVO_PIN);
  _garageServo.write(SERVO_REST);
  Serial.println("[wolf] Servo initialized");
}

void servoTrigger(int angle = SERVO_PRESS) {
  angle = constrain(angle, 0, 180);
  Serial.printf("[wolf] Servo triggering at %d degrees...\n", angle);

  _garageServo.write(angle);
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

**NOTE:** The servo pin needs to be confirmed by the user based on their wiring. GPIO 2 (D4) is the LED pin on NodeMCU so it conflicts. Good alternatives: D1 (GPIO 5), D2 (GPIO 4), D5 (GPIO 14), D6 (GPIO 12), D7 (GPIO 13). The user should pick which pin to use.

- [ ] **Step 5: Create mqtt.h**

Same MQTT structure as ESP32-CAM but without streaming commands. Uses `ESP8266WiFi.h`:

```cpp
#ifndef WOLF_MQTT_H
#define WOLF_MQTT_H

#include <PubSubClient.h>
#include <ArduinoJson.h>
#include <ESP8266WiFi.h>
#include "config.h"
#include "led.h"

WiFiClient _wifiClient;
PubSubClient _mqttClient(_wifiClient);

static unsigned long _mqttLastAttempt = 0;
static unsigned long _mqttBackoff = 1000;

static unsigned long _mqttLastHeartbeat = 0;
static const unsigned long HEARTBEAT_INTERVAL = 60000;

static String _commandTopic;
static String _statusTopic;

// Forward declaration — defined in servo.h
void servoSetup();
void servoTrigger(int angle);

void _mqttCallback(char* topic, byte* payload, unsigned int length) {
  Serial.printf("[wolf] MQTT message on %s (%d bytes)\n", topic, length);

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

  if (strcmp(action, "trigger_servo") == 0) {
    int angle = doc["angle"] | 90;
    Serial.printf("[wolf] Trigger servo command (angle: %d)\n", angle);
    servoTrigger(angle);

  } else {
    Serial.printf("[wolf] Unknown action: %s\n", action);
  }
}

bool mqttConnect() {
  WolfConfig& cfg = configGet();

  _commandTopic = "wolf/" + cfg.deviceId + "/command";
  _statusTopic  = "wolf/" + cfg.deviceId + "/status";

  String clientId = "wolf-" + cfg.deviceId;

  _mqttClient.setServer(cfg.mqttHost.c_str(), cfg.mqttPort);
  _mqttClient.setCallback(_mqttCallback);
  _mqttClient.setKeepAlive(60);
  _mqttClient.setBufferSize(512);

  Serial.printf("[wolf] Connecting to MQTT %s:%d as %s\n",
    cfg.mqttHost.c_str(), cfg.mqttPort, clientId.c_str());

  bool connected = _mqttClient.connect(
    clientId.c_str(),
    nullptr,
    nullptr,
    _statusTopic.c_str(),
    1,
    true,
    "offline"
  );

  if (!connected) {
    Serial.printf("[wolf] MQTT connect failed, rc=%d\n", _mqttClient.state());
    return false;
  }

  _mqttClient.publish(_statusTopic.c_str(), "online", true);
  _mqttClient.subscribe(_commandTopic.c_str(), 1);
  Serial.printf("[wolf] Subscribed to %s\n", _commandTopic.c_str());

  _mqttBackoff = 2000;
  return true;
}

void mqttReconnect() {
  if (_mqttClient.connected()) return;

  unsigned long now = millis();
  if (now - _mqttLastAttempt < _mqttBackoff) return;

  _mqttLastAttempt = now;
  ledFastBlink();

  if (mqttConnect()) {
    ledSolid();
  } else {
    _mqttBackoff = min(_mqttBackoff * 2, (unsigned long)30000);
  }
}

void mqttHeartbeat() {
  if (!_mqttClient.connected()) return;

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

void mqttLoop() {
  if (!_mqttClient.connected()) {
    mqttReconnect();
  }
  _mqttClient.loop();
  mqttHeartbeat();
}

#endif
```

- [ ] **Step 6: Create wolf_esp8266_v1.ino**

```cpp
/*
 * Wolf ESP8266 Firmware
 *
 * Servo-only variant — no camera, no streaming.
 * Connects to WiFi + MQTT, listens for trigger_servo commands.
 *
 * Board: NodeMCU ESP8266 (HiLetgo)
 * Libraries: PubSubClient, ArduinoJson, Servo
 */

#include "config.h"
#include "servo.h"
#include "mqtt.h"

bool setupMode = false;

void setup() {
  Serial.begin(115200);
  delay(1000);
  Serial.println("\n=============================");
  Serial.println("  Wolf ESP8266 v1.0");
  Serial.println("=============================");

  ledInit();

  // Load config or enter setup mode
  if (configLoad()) {
    Serial.printf("[wolf] Config loaded for device: %s\n", configGet().deviceId.c_str());

    if (!configConnectWifi()) {
      Serial.println("[wolf] WiFi failed — entering setup mode");
      setupMode = true;
      configClear();
      configStartPortal();
      return;
    }

  } else if (configNeedsProvision()) {
    Serial.println("[wolf] Partial config found — need to provision");
    configLoadPartial();

    if (!configConnectWifi()) {
      Serial.println("[wolf] WiFi failed — entering setup mode");
      setupMode = true;
      configClear();
      configStartPortal();
      return;
    }

    if (!configFetchProvision()) {
      Serial.println("[wolf] Provisioning failed — entering setup mode");
      setupMode = true;
      configClear();
      configStartPortal();
      return;
    }

  } else {
    Serial.println("[wolf] No config found — entering setup mode");
    setupMode = true;
    configStartPortal();
    return;
  }

  // Initialize servo
  servoSetup();

  // Connect to MQTT
  if (mqttConnect()) {
    ledSolid();
    Serial.println("[wolf] Ready — waiting for commands");
  } else {
    ledFastBlink();
    Serial.println("[wolf] MQTT connect failed — will retry in loop");
  }
}

void loop() {
  if (setupMode) {
    configPortalLoop();
    return;
  }

  if (WiFi.status() != WL_CONNECTED) {
    Serial.println("[wolf] WiFi lost — reconnecting");
    ledFastBlink();
    configConnectWifi();
  }

  mqttLoop();
  ledUpdate();
}
```

- [ ] **Step 7: Verify firmware compiles**

Open in Arduino IDE. Select board: **NodeMCU 1.0 (ESP-12E Module)**. Required libraries:
- PubSubClient (already installed)
- ArduinoJson (already installed)
- Servo (built-in with ESP8266 board package)

The ESP8266 board package must be installed: Arduino IDE → Preferences → Additional Board Manager URLs → add `https://arduino.esp8266.com/stable/package_esp8266com_index.json` → Tools → Board Manager → search "esp8266" → install.

---

### Task 8: Final Integration Test

- [ ] **Step 1: Run full backend test suite**

Run: `docker compose exec app php artisan test`
Expected: All tests pass

- [ ] **Step 2: Run frontend build**

Run: `docker compose exec app npm run build`
Expected: Build succeeds

- [ ] **Step 3: Manual test checklist**

1. **Admin — Create ESP8266 device:** Go to Devices → Add Device → verify dropdown shows "ESP32-CAM" and "ESP8266" → select ESP8266 → create
2. **Admin — Create ESP32-CAM device:** Same flow, select ESP32-CAM
3. **ESP8266 user dashboard:** Log in as user with ESP8266 device → verify only GarageButton shows (no StreamView)
4. **ESP32-CAM user dashboard:** Log in as user with ESP32-CAM device → verify both StreamView and GarageButton show
5. **Edit device type:** Edit a device → change type → verify dashboard updates accordingly
