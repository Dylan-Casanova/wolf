# Device Pairing Redesign Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace manual device creation with ESP self-registration + user claiming flow, support multiple devices per user.

**Architecture:** New `POST /api/device/register` and `GET /api/device/{deviceId}/status` API endpoints replace the old provisioning endpoint. ESP firmware simplified: device_id hardcoded, captive portal only collects WiFi credentials, new polling mode waits for user to claim device via web UI. Database migration makes `user_id` nullable and removes its unique constraint.

**Tech Stack:** Laravel 11, Inertia.js/React, ESP8266 Arduino (EEPROM, HTTPClient, PubSubClient)

---

### Task 1: Database Migration — Nullable user_id, Remove Unique Constraint

**Files:**
- Create: `database/migrations/2026_06_11_000000_make_device_user_id_nullable.php`
- Modify: `database/factories/DeviceFactory.php`

- [ ] **Step 1: Create the migration**

```bash
docker compose exec app php artisan make:migration make_device_user_id_nullable --table=devices
```

Then replace the generated file contents with:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->change();
            $table->dropUnique(['user_id']);
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->unique('user_id');
            $table->foreignId('user_id')->nullable(false)->change();
        });
    }
};
```

- [ ] **Step 2: Update the DeviceFactory to support unclaimed devices**

In `database/factories/DeviceFactory.php`, add a new state method after the existing `online()` method:

```php
public function unclaimed(): static
{
    return $this->state(fn (array $attributes) => [
        'user_id' => null,
    ]);
}
```

- [ ] **Step 3: Run migration and verify**

Run: `docker compose exec app php artisan migrate`
Expected: Migration runs successfully.

Verify: `docker compose exec app php artisan tinker --execute "Schema::getColumnType('devices', 'user_id');"` — should work without errors.

- [ ] **Step 4: Commit**

```bash
git add database/migrations/*make_device_user_id_nullable* database/factories/DeviceFactory.php
git commit -m "feat: make device user_id nullable, remove unique constraint for multi-device support"
```

---

### Task 2: Device Registration API Endpoint

**Files:**
- Create: `app/Http/Controllers/Api/DeviceRegisterController.php`
- Create: `tests/Feature/Api/DeviceRegisterTest.php`
- Modify: `routes/api.php`

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/Api/DeviceRegisterTest.php`:

```php
<?php

namespace Tests\Feature\Api;

use App\Models\Device;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeviceRegisterTest extends TestCase
{
    use RefreshDatabase;

    public function test_device_can_self_register(): void
    {
        $response = $this->postJson('/api/device/register', [
            'device_id' => 'ESP8266-001',
            'type' => 'esp8266',
            'name' => 'ESP8266-001',
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure(['device_id', 'token']);

        $this->assertDatabaseHas('devices', [
            'device_id' => 'ESP8266-001',
            'type' => 'esp8266',
            'name' => 'ESP8266-001',
            'user_id' => null,
        ]);
    }

    public function test_duplicate_registration_returns_existing_token(): void
    {
        // First registration
        $response1 = $this->postJson('/api/device/register', [
            'device_id' => 'ESP8266-001',
            'type' => 'esp8266',
            'name' => 'ESP8266-001',
        ]);

        $token1 = $response1->json('token');

        // Second registration (same device_id)
        $response2 = $this->postJson('/api/device/register', [
            'device_id' => 'ESP8266-001',
            'type' => 'esp8266',
            'name' => 'ESP8266-001',
        ]);

        $response2->assertOk();
        $token2 = $response2->json('token');

        $this->assertEquals($token1, $token2);
        $this->assertDatabaseCount('devices', 1);
    }

    public function test_registration_validates_device_id_format(): void
    {
        $response = $this->postJson('/api/device/register', [
            'device_id' => 'invalid-format',
            'type' => 'esp8266',
            'name' => 'My Device',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('device_id');
    }

    public function test_registration_requires_all_fields(): void
    {
        $response = $this->postJson('/api/device/register', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['device_id', 'type', 'name']);
    }

    public function test_registration_validates_device_type(): void
    {
        $response = $this->postJson('/api/device/register', [
            'device_id' => 'ESP8266-001',
            'type' => 'invalid_type',
            'name' => 'ESP8266-001',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('type');
    }

    public function test_registration_is_rate_limited(): void
    {
        for ($i = 0; $i < 11; $i++) {
            $response = $this->postJson('/api/device/register', [
                'device_id' => "ESP8266-{$i}",
                'type' => 'esp8266',
                'name' => "ESP8266-{$i}",
            ]);
        }

        $response->assertStatus(429);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `docker compose exec app php artisan test --filter=DeviceRegisterTest`
Expected: All tests FAIL (controller doesn't exist yet).

- [ ] **Step 3: Create the controller**

Create `app/Http/Controllers/Api/DeviceRegisterController.php`:

```php
<?php

namespace App\Http\Controllers\Api;

use App\Enums\DeviceType;
use App\Http\Controllers\Controller;
use App\Models\Device;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\Rule;

class DeviceRegisterController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'device_id' => [
                'required',
                'string',
                'max:255',
                'regex:/^(ESP8266|ESP32)-\d{3,}$/i',
            ],
            'type' => ['required', Rule::in(DeviceType::values())],
            'name' => ['required', 'string', 'max:255'],
        ]);

        $existing = Device::where('device_id', $validated['device_id'])->first();

        if ($existing) {
            return response()->json([
                'device_id' => $existing->device_id,
                'token' => Crypt::decryptString($existing->token_encrypted),
            ]);
        }

        $device = Device::create([
            'name' => $validated['name'],
            'device_id' => $validated['device_id'],
            'type' => $validated['type'],
            'user_id' => null,
            'token_hash' => '',
        ]);

        $token = $device->generateToken();

        return response()->json([
            'device_id' => $device->device_id,
            'token' => $token,
        ], 201);
    }
}
```

- [ ] **Step 4: Add the route**

In `routes/api.php`, add the following after the existing imports at the top:

```php
use App\Http\Controllers\Api\DeviceRegisterController;
```

Then add the route before the existing provisioning route:

```php
// Device self-registration (called by ESP on first boot — no auth, rate limited)
Route::post('/device/register', DeviceRegisterController::class)
    ->middleware('throttle:10,1')
    ->name('device.register');
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `docker compose exec app php artisan test --filter=DeviceRegisterTest`
Expected: All tests PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Api/DeviceRegisterController.php tests/Feature/Api/DeviceRegisterTest.php routes/api.php
git commit -m "feat: add device self-registration API endpoint"
```

---

### Task 3: Device Status Polling API Endpoint

**Files:**
- Create: `app/Http/Controllers/Api/DeviceStatusController.php`
- Create: `tests/Feature/Api/DeviceStatusTest.php`
- Modify: `routes/api.php`

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/Api/DeviceStatusTest.php`:

```php
<?php

namespace Tests\Feature\Api;

use App\Models\Device;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

class DeviceStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_unclaimed_device_returns_paired_false(): void
    {
        $device = Device::factory()->unclaimed()->create();
        $token = $device->generateToken();

        $response = $this->getJson("/api/device/{$device->device_id}/status", [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertOk()
            ->assertJson(['paired' => false]);
    }

    public function test_claimed_device_returns_paired_true_with_mqtt_config(): void
    {
        $device = Device::factory()->create();
        $token = $device->generateToken();

        $response = $this->getJson("/api/device/{$device->device_id}/status", [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertOk()
            ->assertJson(['paired' => true])
            ->assertJsonStructure(['paired', 'mqtt_host', 'mqtt_port']);
    }

    public function test_status_updates_last_seen_at(): void
    {
        $device = Device::factory()->unclaimed()->create(['last_seen_at' => null]);
        $token = $device->generateToken();

        $this->getJson("/api/device/{$device->device_id}/status", [
            'Authorization' => "Bearer {$token}",
        ]);

        $this->assertNotNull($device->fresh()->last_seen_at);
    }

    public function test_status_rejects_invalid_token(): void
    {
        $device = Device::factory()->unclaimed()->create();
        $device->generateToken();

        $response = $this->getJson("/api/device/{$device->device_id}/status", [
            'Authorization' => 'Bearer wrong-token',
        ]);

        $response->assertUnauthorized();
    }

    public function test_status_rejects_missing_token(): void
    {
        $device = Device::factory()->unclaimed()->create();

        $response = $this->getJson("/api/device/{$device->device_id}/status");

        $response->assertUnauthorized();
    }

    public function test_status_returns_404_for_unknown_device(): void
    {
        $response = $this->getJson('/api/device/NONEXISTENT/status', [
            'Authorization' => 'Bearer some-token',
        ]);

        $response->assertNotFound();
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `docker compose exec app php artisan test --filter=DeviceStatusTest`
Expected: All tests FAIL.

- [ ] **Step 3: Create the controller**

Create `app/Http/Controllers/Api/DeviceStatusController.php`:

```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Device;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DeviceStatusController extends Controller
{
    public function __invoke(Request $request, string $deviceId): JsonResponse
    {
        $device = Device::where('device_id', $deviceId)->first();

        if (! $device) {
            return response()->json(['error' => 'Device not found'], 404);
        }

        $token = $request->bearerToken();

        if (! $token || ! $device->verifyToken($token)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $device->update(['last_seen_at' => now()]);

        if ($device->user_id === null) {
            return response()->json(['paired' => false]);
        }

        return response()->json([
            'paired' => true,
            'mqtt_host' => parse_url(config('app.url'), PHP_URL_HOST),
            'mqtt_port' => 1883,
        ]);
    }
}
```

- [ ] **Step 4: Add the route**

In `routes/api.php`, add the import:

```php
use App\Http\Controllers\Api\DeviceStatusController;
```

Add the route:

```php
// Device status polling (called by ESP every 30s — authenticated by device token)
Route::get('/device/{deviceId}/status', DeviceStatusController::class)
    ->name('device.status');
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `docker compose exec app php artisan test --filter=DeviceStatusTest`
Expected: All tests PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Api/DeviceStatusController.php tests/Feature/Api/DeviceStatusTest.php routes/api.php
git commit -m "feat: add device status polling API endpoint with token auth"
```

---

### Task 4: Device Claiming — Backend

**Files:**
- Create: `app/Http/Controllers/DeviceClaimController.php`
- Create: `tests/Feature/DeviceClaimTest.php`
- Modify: `routes/web.php`

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/DeviceClaimTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeviceClaimTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_claim_unclaimed_device(): void
    {
        $user = User::factory()->create();
        $device = Device::factory()->unclaimed()->create(['device_id' => 'ESP8266-001']);

        $response = $this->actingAs($user)->post('/devices/claim', [
            'device_id' => 'ESP8266-001',
        ]);

        $response->assertRedirect('/dashboard');
        $this->assertEquals($user->id, $device->fresh()->user_id);
    }

    public function test_user_cannot_claim_device_owned_by_another(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        Device::factory()->create(['device_id' => 'ESP8266-001', 'user_id' => $otherUser->id]);

        $response = $this->actingAs($user)->post('/devices/claim', [
            'device_id' => 'ESP8266-001',
        ]);

        $response->assertSessionHasErrors('device_id');
    }

    public function test_user_cannot_claim_device_they_already_own(): void
    {
        $user = User::factory()->create();
        Device::factory()->create(['device_id' => 'ESP8266-001', 'user_id' => $user->id]);

        $response = $this->actingAs($user)->post('/devices/claim', [
            'device_id' => 'ESP8266-001',
        ]);

        $response->assertSessionHasErrors('device_id');
    }

    public function test_claim_returns_error_for_unknown_device(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/devices/claim', [
            'device_id' => 'NONEXISTENT',
        ]);

        $response->assertSessionHasErrors('device_id');
    }

    public function test_claim_requires_device_id(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/devices/claim', []);

        $response->assertSessionHasErrors('device_id');
    }

    public function test_guest_cannot_claim_device(): void
    {
        $response = $this->post('/devices/claim', ['device_id' => 'ESP8266-001']);

        $response->assertRedirect('/login');
    }

    public function test_claim_page_is_accessible_to_logged_in_users(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/devices/claim');

        $response->assertOk();
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `docker compose exec app php artisan test --filter=DeviceClaimTest`
Expected: All tests FAIL.

- [ ] **Step 3: Create the controller**

Create `app/Http/Controllers/DeviceClaimController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Models\Device;
use Illuminate\Http\Request;
use Inertia\Inertia;

class DeviceClaimController extends Controller
{
    public function create()
    {
        return Inertia::render('Devices/Claim');
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'device_id' => ['required', 'string'],
        ]);

        $device = Device::where('device_id', $validated['device_id'])->first();

        if (! $device) {
            return back()->withErrors(['device_id' => 'Device not found.']);
        }

        if ($device->user_id === $request->user()->id) {
            return back()->withErrors(['device_id' => 'You already own this device.']);
        }

        if ($device->user_id !== null) {
            return back()->withErrors(['device_id' => 'Device is already claimed.']);
        }

        $device->update(['user_id' => $request->user()->id]);

        return redirect()->route('dashboard');
    }
}
```

- [ ] **Step 4: Add the routes**

In `routes/web.php`, add the import:

```php
use App\Http\Controllers\DeviceClaimController;
```

Add the routes inside the `Route::middleware(['auth', 'verified'])` group, after the dashboard route:

```php
    // Device claiming (any logged-in user)
    Route::get('/devices/claim', [DeviceClaimController::class, 'create'])->name('devices.claim');
    Route::post('/devices/claim', [DeviceClaimController::class, 'store']);
```

**Important:** Place these routes BEFORE the admin-only `Route::middleware('admin')` group so they don't get blocked by the admin middleware.

- [ ] **Step 5: Create a minimal Claim page placeholder for tests**

Create `resources/js/Pages/Devices/Claim.tsx`:

```tsx
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import TextInput from '@/Components/TextInput';
import { Head, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';

export default function Claim() {
    const { data, setData, post, processing, errors } = useForm({
        device_id: '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post('/devices/claim');
    };

    return (
        <AuthenticatedLayout
            header={
                <h2 className="text-xl font-semibold leading-tight text-gray-800">
                    Claim a Device
                </h2>
            }
        >
            <Head title="Claim Device" />

            <div className="py-12">
                <div className="mx-auto max-w-2xl sm:px-6 lg:px-8">
                    <div className="bg-white p-6 shadow-sm sm:rounded-lg">
                        <p className="mb-4 text-sm text-gray-600">
                            Enter the Device ID printed on your hardware to link it to your account.
                        </p>
                        <form onSubmit={submit} className="space-y-6">
                            <div>
                                <InputLabel htmlFor="device_id" value="Device ID" />
                                <TextInput
                                    id="device_id"
                                    value={data.device_id}
                                    onChange={(e) => setData('device_id', e.target.value)}
                                    className="mt-1 block w-full"
                                    required
                                    isFocused
                                    placeholder="e.g. ESP8266-001"
                                />
                                <InputError message={errors.device_id} className="mt-2" />
                            </div>

                            <div className="flex items-center justify-end">
                                <PrimaryButton disabled={processing}>
                                    Claim Device
                                </PrimaryButton>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `docker compose exec app php artisan test --filter=DeviceClaimTest`
Expected: All tests PASS.

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/DeviceClaimController.php tests/Feature/DeviceClaimTest.php routes/web.php resources/js/Pages/Devices/Claim.tsx
git commit -m "feat: add device claiming flow — any user can claim unclaimed devices"
```

---

### Task 5: Update Admin Device Management for Multi-Device

**Files:**
- Modify: `app/Http/Controllers/DeviceController.php`
- Modify: `app/Http/Requests/StoreDeviceRequest.php`
- Modify: `app/Http/Requests/UpdateDeviceRequest.php`
- Modify: `resources/js/Pages/Devices/Index.tsx`
- Modify: `resources/js/Pages/Devices/Edit.tsx`
- Modify: `tests/Feature/DeviceManagementTest.php`

- [ ] **Step 1: Update StoreDeviceRequest — remove user_id unique constraint**

Replace the contents of `app/Http/Requests/StoreDeviceRequest.php`:

```php
<?php

namespace App\Http\Requests;

use App\Enums\DeviceType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDeviceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'device_id' => ['required', 'string', 'max:255', 'unique:devices,device_id'],
            'user_id' => ['nullable', 'exists:users,id'],
            'type' => ['required', Rule::in(DeviceType::values())],
        ];
    }
}
```

- [ ] **Step 2: Update UpdateDeviceRequest — remove user_id unique constraint**

Replace the contents of `app/Http/Requests/UpdateDeviceRequest.php`:

```php
<?php

namespace App\Http\Requests;

use App\Enums\DeviceType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateDeviceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $deviceId = $this->route('device')->id;

        return [
            'name' => ['required', 'string', 'max:255'],
            'device_id' => ['required', 'string', 'max:255', "unique:devices,device_id,{$deviceId}"],
            'user_id' => ['nullable', 'exists:users,id'],
            'type' => ['required', Rule::in(DeviceType::values())],
        ];
    }
}
```

- [ ] **Step 3: Update DeviceController — show all users (not just unassigned), allow null user_id**

Replace the contents of `app/Http/Controllers/DeviceController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Enums\DeviceType;
use App\Http\Requests\StoreDeviceRequest;
use App\Http\Requests\UpdateDeviceRequest;
use App\Models\Device;
use App\Models\User;
use Inertia\Inertia;

class DeviceController extends Controller
{
    public function index()
    {
        $devices = Device::with('user')->latest()->get();

        return Inertia::render('Devices/Index', [
            'devices' => $devices,
        ]);
    }

    public function create()
    {
        $users = User::select('id', 'name', 'email')
            ->orderBy('name')
            ->get();

        return Inertia::render('Devices/Create', [
            'users' => $users,
            'deviceTypes' => DeviceType::options(),
        ]);
    }

    public function store(StoreDeviceRequest $request)
    {
        $device = Device::create([
            'name' => $request->name,
            'device_id' => $request->device_id,
            'user_id' => $request->user_id ?: null,
            'type' => $request->type,
            'token_hash' => '',
        ]);

        $token = $device->generateToken();

        return redirect()->route('devices.index')->with('device_token', $token);
    }

    public function edit(Device $device)
    {
        $device->load('user');

        $users = User::select('id', 'name', 'email')
            ->orderBy('name')
            ->get();

        return Inertia::render('Devices/Edit', [
            'device' => $device,
            'users' => $users,
            'deviceTypes' => DeviceType::options(),
        ]);
    }

    public function update(UpdateDeviceRequest $request, Device $device)
    {
        $device->update([
            'name' => $request->name,
            'device_id' => $request->device_id,
            'user_id' => $request->user_id ?: null,
            'type' => $request->type,
        ]);

        return redirect()->route('devices.index');
    }

    public function destroy(Device $device)
    {
        $device->delete();

        return redirect()->route('devices.index');
    }

    public function regenerateToken(Device $device)
    {
        $token = $device->generateToken();

        return back()->with('device_token', $token);
    }
}
```

- [ ] **Step 4: Update Index.tsx — show "Unclaimed" for devices with no user**

In `resources/js/Pages/Devices/Index.tsx`, find the user column cell:

```tsx
                                            <td className="whitespace-nowrap px-6 py-4 text-sm text-gray-500">
                                                {device.user ? (
                                                    <div>
                                                        <div>
                                                            {device.user.name}
                                                        </div>
                                                        <div className="text-xs text-gray-400">
                                                            {device.user.email}
                                                        </div>
                                                    </div>
                                                ) : (
                                                    <span className="text-gray-400">
                                                        —
                                                    </span>
                                                )}
                                            </td>
```

Replace the `—` span with:

```tsx
                                                    <span className="inline-flex items-center rounded-full bg-yellow-100 px-2.5 py-0.5 text-xs font-medium text-yellow-800">
                                                        Unclaimed
                                                    </span>
```

- [ ] **Step 5: Update Edit.tsx — make user_id optional (add "Unclaimed" option)**

In `resources/js/Pages/Devices/Edit.tsx`, find the user_id select and remove the `required` attribute. Update the empty option text:

Change:
```tsx
                                    <option value="">Select a user...</option>
```

To:
```tsx
                                    <option value="">Unclaimed</option>
```

- [ ] **Step 6: Update the TypeScript Device interface**

In `resources/js/types/index.d.ts`, change `user_id` to be nullable:

```typescript
export interface Device {
    id: number;
    user_id: number | null;
    name: string;
    device_id: string;
    type: 'esp32_cam' | 'esp8266';
    is_online: boolean;
    last_seen_at: string | null;
    meta: Record<string, unknown> | null;
    user?: User;
}
```

- [ ] **Step 7: Update DeviceManagementTest — fix multi-device expectations**

In `tests/Feature/DeviceManagementTest.php`, update the test `test_cannot_create_device_for_user_who_already_has_one` — this should now PASS since we allow multiple devices per user. Replace it with:

```php
public function test_user_can_have_multiple_devices(): void
{
    $admin = User::factory()->admin()->create();
    $user = User::factory()->create();
    Device::factory()->create(['user_id' => $user->id]);

    $response = $this->actingAs($admin)->post('/devices', [
        'name' => 'Second Device',
        'device_id' => 'esp32-002',
        'user_id' => $user->id,
        'type' => 'esp32_cam',
    ]);

    $response->assertRedirect('/devices');
    $this->assertEquals(2, Device::where('user_id', $user->id)->count());
}
```

- [ ] **Step 8: Run all tests**

Run: `docker compose exec app php artisan test`
Expected: All tests PASS.

- [ ] **Step 9: Commit**

```bash
git add app/Http/Controllers/DeviceController.php app/Http/Requests/StoreDeviceRequest.php app/Http/Requests/UpdateDeviceRequest.php resources/js/Pages/Devices/Index.tsx resources/js/Pages/Devices/Edit.tsx resources/js/types/index.d.ts tests/Feature/DeviceManagementTest.php
git commit -m "feat: update admin device management for multi-device and unclaimed devices"
```

---

### Task 6: Update Dashboard for Multi-Device

**Files:**
- Modify: `routes/web.php` (dashboard route)
- Modify: `resources/js/Pages/Dashboard.tsx`
- Modify: `app/Http/Controllers/GarageController.php`
- Modify: `app/Http/Controllers/StreamController.php`

- [ ] **Step 1: Update the dashboard route to pass all devices**

In `routes/web.php`, replace the dashboard route:

```php
    Route::get('/dashboard', function () {
        $devices = auth()->user()->devices()->get()->map(fn ($device) => [
            'id' => $device->id,
            'name' => $device->name,
            'device_id' => $device->device_id,
            'type' => $device->type->value,
            'is_online' => $device->is_online,
        ]);

        return Inertia::render('Dashboard', [
            'devices' => $devices,
        ]);
    })->name('dashboard');
```

- [ ] **Step 2: Update Dashboard.tsx to support multiple devices**

Replace the contents of `resources/js/Pages/Dashboard.tsx`:

```tsx
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import StreamView, { StreamViewHandle } from '@/Components/StreamView';
import GarageButton from '@/Components/GarageButton';
import { Head, Link } from '@inertiajs/react';
import { useRef, useState } from 'react';

interface DeviceInfo {
    id: number;
    name: string;
    device_id: string;
    type: 'esp32_cam' | 'esp8266';
    is_online: boolean;
}

interface DashboardProps {
    devices: DeviceInfo[];
}

export default function Dashboard({ devices }: DashboardProps) {
    const [selectedIndex, setSelectedIndex] = useState(0);
    const streamRef = useRef<StreamViewHandle>(null);
    const wasStreamingRef = useRef(false);

    const device = devices[selectedIndex] ?? null;
    const hasCamera = device?.type === 'esp32_cam';

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
                    {devices.length === 0 ? (
                        <div className="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                            <div className="p-6 text-center text-gray-500">
                                <p className="mb-4">No devices linked to your account.</p>
                                <Link
                                    href="/devices/claim"
                                    className="inline-flex items-center rounded-md bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700"
                                >
                                    Claim a Device
                                </Link>
                            </div>
                        </div>
                    ) : (
                        <>
                            {devices.length > 1 && (
                                <div className="mb-4">
                                    <select
                                        value={selectedIndex}
                                        onChange={(e) => setSelectedIndex(Number(e.target.value))}
                                        className="rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                    >
                                        {devices.map((d, i) => (
                                            <option key={d.id} value={i}>
                                                {d.name} ({d.device_id}) {d.is_online ? '' : '— offline'}
                                            </option>
                                        ))}
                                    </select>
                                </div>
                            )}
                            <div className="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                                <div className="p-6 text-gray-900 flex flex-col items-center gap-4">
                                    {hasCamera && <StreamView ref={streamRef} />}
                                    {device && (
                                        <GarageButton
                                            deviceId={device.id}
                                            onTriggerStart={handleTriggerStart}
                                            onTriggerComplete={handleTriggerComplete}
                                        />
                                    )}
                                </div>
                            </div>
                        </>
                    )}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
```

- [ ] **Step 3: Update GarageController to accept device_id in request**

Replace the contents of `app/Http/Controllers/GarageController.php`:

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

        $request->validate([
            'device_id' => ['sometimes', 'integer'],
            'angle' => ['sometimes', 'integer', 'min:0', 'max:180'],
        ]);

        $device = $request->device_id
            ? $user->devices()->where('id', $request->device_id)->first()
            : $user->devices()->first();

        if (! $device) {
            return response()->json(['message' => 'No device registered.'], 422);
        }

        $angle = $request->integer('angle', 130);

        $this->device->triggerServo($device, $angle);

        return response()->json(['message' => 'Command sent.']);
    }
}
```

- [ ] **Step 4: Update GarageButton to send device_id**

In `resources/js/Components/GarageButton.tsx`, update the axios call:

Change:
```tsx
            await axios.post('/garage/trigger', { angle });
```

To:
```tsx
            await axios.post('/garage/trigger', { angle, device_id: deviceId });
```

- [ ] **Step 5: Run all tests**

Run: `docker compose exec app php artisan test`
Expected: All tests PASS.

- [ ] **Step 6: Commit**

```bash
git add routes/web.php resources/js/Pages/Dashboard.tsx app/Http/Controllers/GarageController.php resources/js/Components/GarageButton.tsx
git commit -m "feat: update dashboard and garage trigger for multi-device support"
```

---

### Task 7: Remove Old Provisioning Endpoint

**Files:**
- Delete: `app/Http/Controllers/Api/DeviceProvisionController.php`
- Modify: `routes/api.php`

- [ ] **Step 1: Remove the route**

In `routes/api.php`, remove these lines:

```php
// Device provisioning (called by ESP32 during setup — no auth required)
Route::get('/device/{deviceId}/provision', DeviceProvisionController::class)
    ->name('device.provision');
```

And remove the import:

```php
use App\Http\Controllers\Api\DeviceProvisionController;
```

- [ ] **Step 2: Delete the controller**

Delete the file `app/Http/Controllers/Api/DeviceProvisionController.php`.

- [ ] **Step 3: Run all tests**

Run: `docker compose exec app php artisan test`
Expected: All tests PASS.

- [ ] **Step 4: Commit**

```bash
git add routes/api.php
git rm app/Http/Controllers/Api/DeviceProvisionController.php
git commit -m "refactor: remove old device provisioning endpoint, replaced by register + status"
```

---

### Task 8: ESP8266 Firmware — Simplify Captive Portal and Add Registration

**Files:**
- Modify: `/Users/mr.casanova/Documents/Arduino/wolf-esp8266-v1/config.h`
- Modify: `/Users/mr.casanova/Documents/Arduino/wolf-esp8266-v1/wolf-esp8266-v1.ino`

- [ ] **Step 1: Add hardcoded constants to config.h**

At the top of `config.h`, after the `WOLF_PROVISION_BASE` define, add:

```cpp
#define WOLF_DEVICE_ID    "ESP8266-001"
#define WOLF_DEVICE_TYPE  "esp8266"
#define WOLF_DEVICE_NAME  "ESP8266-001"
```

- [ ] **Step 2: Simplify the EEPROM layout**

Replace the EEPROM layout section in `config.h` (the defines and comments) with:

```cpp
// ── EEPROM layout ───────────────────────────────────────────
// Simplified: device_id is hardcoded, server_url derived from WOLF_PROVISION_BASE.
// Layout: [wifiSsid(65)][wifiPass(65)][mqttHost(65)][mqttPort(2)][deviceToken(65)][rstFlag(1)]
#define EEPROM_SIZE 400
#define ADDR_WIFI_SSID     0    // 1 + 64
#define ADDR_WIFI_PASS     65   // 1 + 64
#define ADDR_MQTT_HOST     130  // 1 + 64
#define ADDR_MQTT_PORT     195  // 2 bytes (uint16)
#define ADDR_DEVICE_TOKEN  197  // 1 + 64
#define ADDR_RST_FLAG      262  // 1 byte
```

- [ ] **Step 3: Simplify the WolfConfig struct**

Replace the `WolfConfig` struct:

```cpp
struct WolfConfig {
  String wifiSsid;
  String wifiPassword;
  String mqttHost;     // received after pairing
  int    mqttPort;     // default 1883
  String deviceToken;  // received during registration
};
```

- [ ] **Step 4: Replace configLoad, configNeedsProvision, configLoadPartial, configSave, configClear**

Replace the config persistence functions with:

```cpp
// ── Config persistence ──────────────────────────────────────

// Returns true if device is fully configured (has WiFi + MQTT config)
bool configLoad() {
  EEPROM.begin(EEPROM_SIZE);
  _wolfConfig.wifiSsid     = _eepromReadString(ADDR_WIFI_SSID);
  _wolfConfig.wifiPassword  = _eepromReadString(ADDR_WIFI_PASS);
  _wolfConfig.mqttHost      = _eepromReadString(ADDR_MQTT_HOST);
  _wolfConfig.mqttPort      = _eepromReadUint16(ADDR_MQTT_PORT);
  _wolfConfig.deviceToken   = _eepromReadString(ADDR_DEVICE_TOKEN);
  EEPROM.end();

  if (_wolfConfig.mqttPort == 0) _wolfConfig.mqttPort = 1883;

  return _wolfConfig.wifiSsid.length() > 0
      && _wolfConfig.mqttHost.length() > 0
      && _wolfConfig.deviceToken.length() > 0;
}

// Has WiFi but no token yet — needs to register
bool configNeedsRegistration() {
  EEPROM.begin(EEPROM_SIZE);
  String ssid  = _eepromReadString(ADDR_WIFI_SSID);
  String token = _eepromReadString(ADDR_DEVICE_TOKEN);
  EEPROM.end();
  return ssid.length() > 0 && token.length() == 0;
}

// Has WiFi + token but no MQTT config — needs to poll for pairing
bool configNeedsPairing() {
  EEPROM.begin(EEPROM_SIZE);
  String ssid     = _eepromReadString(ADDR_WIFI_SSID);
  String token    = _eepromReadString(ADDR_DEVICE_TOKEN);
  String mqttHost = _eepromReadString(ADDR_MQTT_HOST);
  EEPROM.end();
  return ssid.length() > 0 && token.length() > 0 && mqttHost.length() == 0;
}

void configSaveWifi() {
  EEPROM.begin(EEPROM_SIZE);
  _eepromWriteString(ADDR_WIFI_SSID, _wolfConfig.wifiSsid);
  _eepromWriteString(ADDR_WIFI_PASS, _wolfConfig.wifiPassword);
  EEPROM.commit();
  EEPROM.end();
}

void configSaveToken() {
  EEPROM.begin(EEPROM_SIZE);
  _eepromWriteString(ADDR_DEVICE_TOKEN, _wolfConfig.deviceToken);
  EEPROM.commit();
  EEPROM.end();
}

void configSaveMqtt() {
  EEPROM.begin(EEPROM_SIZE);
  _eepromWriteString(ADDR_MQTT_HOST, _wolfConfig.mqttHost);
  _eepromWriteUint16(ADDR_MQTT_PORT, _wolfConfig.mqttPort);
  EEPROM.commit();
  EEPROM.end();
}

void configClear() {
  EEPROM.begin(EEPROM_SIZE);
  for (int i = 0; i < EEPROM_SIZE; i++) {
    EEPROM.write(i, 0);
  }
  EEPROM.commit();
  EEPROM.end();
}

WolfConfig& configGet() {
  return _wolfConfig;
}
```

- [ ] **Step 5: Replace configFetchProvision with configRegister and configPollStatus**

Remove the entire `configFetchProvision()` function and replace with:

```cpp
// ── Register device with backend ─────────────────────────────

bool configRegister() {
  String url = String(WOLF_PROVISION_BASE) + "/api/device/register";

  Serial.printf("[wolf] Registering device at %s\n", url.c_str());

  WiFiClientSecure client;
  client.setInsecure();
  HTTPClient http;
  http.begin(client, url);
  http.setTimeout(10000);
  http.addHeader("Content-Type", "application/json");

  String body = "{\"device_id\":\"" + String(WOLF_DEVICE_ID) +
                "\",\"type\":\"" + String(WOLF_DEVICE_TYPE) +
                "\",\"name\":\"" + String(WOLF_DEVICE_NAME) + "\"}";

  int httpCode = http.POST(body);

  if (httpCode != 200 && httpCode != 201) {
    Serial.printf("[wolf] Registration failed (HTTP %d)\n", httpCode);
    if (httpCode > 0) {
      Serial.printf("[wolf] Response: %s\n", http.getString().c_str());
    }
    http.end();
    return false;
  }

  String payload = http.getString();
  http.end();

  Serial.printf("[wolf] Registration response: %s\n", payload.c_str());

  JsonDocument doc;
  DeserializationError err = deserializeJson(doc, payload);
  if (err) {
    Serial.printf("[wolf] JSON parse error: %s\n", err.c_str());
    return false;
  }

  const char* token = doc["token"];
  if (!token) {
    Serial.println("[wolf] Registration response missing token");
    return false;
  }

  _wolfConfig.deviceToken = String(token);
  configSaveToken();

  Serial.println("[wolf] Registered successfully — token saved");
  return true;
}

// ── Poll for pairing status ──────────────────────────────────

// Returns: 0 = not paired, 1 = paired (mqtt config saved), -1 = error
int configPollStatus() {
  String url = String(WOLF_PROVISION_BASE) + "/api/device/" + String(WOLF_DEVICE_ID) + "/status";

  WiFiClientSecure client;
  client.setInsecure();
  HTTPClient http;
  http.begin(client, url);
  http.setTimeout(10000);
  http.addHeader("Authorization", "Bearer " + _wolfConfig.deviceToken);

  int httpCode = http.GET();

  if (httpCode != 200) {
    Serial.printf("[wolf] Status poll failed (HTTP %d)\n", httpCode);
    http.end();
    return -1;
  }

  String payload = http.getString();
  http.end();

  JsonDocument doc;
  DeserializationError err = deserializeJson(doc, payload);
  if (err) {
    Serial.printf("[wolf] JSON parse error: %s\n", err.c_str());
    return -1;
  }

  bool paired = doc["paired"] | false;

  if (!paired) {
    return 0;
  }

  const char* mqttHost = doc["mqtt_host"];
  int mqttPort = doc["mqtt_port"] | 1883;

  if (!mqttHost) {
    Serial.println("[wolf] Paired but missing mqtt_host");
    return -1;
  }

  _wolfConfig.mqttHost = String(mqttHost);
  _wolfConfig.mqttPort = mqttPort;
  configSaveMqtt();

  Serial.printf("[wolf] Paired! MQTT: %s:%d\n", _wolfConfig.mqttHost.c_str(), _wolfConfig.mqttPort);
  return 1;
}
```

- [ ] **Step 6: Simplify the captive portal HTML — remove Device ID field, make password plain text**

Replace the `PORTAL_HTML` constant:

```cpp
static const char PORTAL_HTML[] PROGMEM = R"rawliteral(
<!DOCTYPE html>
<html>
<head>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Wolf Setup</title>
  <style>
    body { font-family: -apple-system, sans-serif; max-width: 400px; margin: 40px auto; padding: 0 20px; background: #f5f5f5; }
    h1 { color: #4f46e5; font-size: 1.5rem; }
    label { display: block; margin-top: 12px; font-size: 0.875rem; font-weight: 600; color: #374151; }
    input { width: 100%; padding: 8px 12px; margin-top: 4px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 1rem; box-sizing: border-box; }
    button { margin-top: 20px; width: 100%; padding: 10px; background: #4f46e5; color: white; border: none; border-radius: 6px; font-size: 1rem; font-weight: 600; cursor: pointer; }
    button:hover { background: #4338ca; }
    .note { margin-top: 16px; font-size: 0.75rem; color: #6b7280; }
  </style>
</head>
<body>
  <h1>Wolf Setup</h1>
  <form method="POST" action="/save">
    <label>WiFi Network Name</label>
    <input name="wifiSsid" required placeholder="Your WiFi name">

    <label>WiFi Password</label>
    <input name="wifiPass" type="text" placeholder="Your WiFi password">

    <button type="submit">Connect</button>
  </form>
  <p class="note">Your device will connect to WiFi and configure itself automatically.</p>
</body>
</html>
)rawliteral";
```

- [ ] **Step 7: Update the portal save handler — only save WiFi (no device ID)**

Replace `_handlePortalSave`:

```cpp
void _handlePortalSave() {
  _wolfConfig.wifiSsid     = _portalServer.arg("wifiSsid");
  _wolfConfig.wifiPassword  = _portalServer.arg("wifiPass");

  Serial.printf("[wolf] Portal saving — WiFi: '%s'\n", _wolfConfig.wifiSsid.c_str());

  configSaveWifi();

  _portalServer.send(200, "text/html",
    "<html><body><h1>Connecting...</h1><p>Your device is connecting to WiFi and configuring itself. This page will not update — check the device LED.</p></body></html>");

  delay(1500);
  ESP.restart();
}
```

- [ ] **Step 8: Update the double-reset detection function**

In the `configCheckDoubleReset` function, update the `ADDR_RST_FLAG` references to use the new address. The function itself doesn't change — it already uses `ADDR_RST_FLAG` which is now at address 262 (was 392).

- [ ] **Step 9: Rewrite wolf-esp8266-v1.ino for the new boot flow**

Replace the entire contents of `wolf-esp8266-v1.ino`:

```cpp
/*
 * Wolf ESP8266 Firmware v2.0
 *
 * Servo-only variant — no camera, no streaming.
 * Self-registers with backend, waits for user to claim, then connects to MQTT.
 *
 * Modes:
 *   1. Setup — captive portal for WiFi credentials only
 *   2. Registration — registers with backend, gets token
 *   3. Polling — polls backend every 30s waiting for user to claim
 *   4. Normal — connects to MQTT, waits for servo commands
 *
 * Board: NodeMCU ESP8266 (HiLetgo)
 * Libraries: PubSubClient, ArduinoJson, Servo
 */

#include "config.h"
#include "servo.h"
#include "mqtt.h"

enum WolfMode { MODE_SETUP, MODE_REGISTER, MODE_POLLING, MODE_NORMAL };
WolfMode currentMode = MODE_SETUP;

static unsigned long lastPoll = 0;
static const unsigned long POLL_INTERVAL = 30000; // 30 seconds

void setup() {
  Serial.begin(115200);
  delay(1000);
  Serial.println("\n=============================");
  Serial.printf("  Wolf ESP8266 v2.0 [%s]\n", WOLF_DEVICE_ID);
  Serial.println("=============================");

  ledInit();

  // Double-reset detection
  if (configCheckDoubleReset()) {
    currentMode = MODE_SETUP;
    configStartPortal();
    return;
  }

  // Determine mode based on EEPROM state
  if (configLoad()) {
    // Full config: WiFi + token + MQTT
    Serial.printf("[wolf] Full config loaded — MQTT: %s:%d\n",
      configGet().mqttHost.c_str(), configGet().mqttPort);

    if (!configConnectWifi()) {
      Serial.println("[wolf] WiFi failed — entering setup mode");
      currentMode = MODE_SETUP;
      configClear();
      configStartPortal();
      return;
    }

    currentMode = MODE_NORMAL;
    servoSetup();

    if (mqttConnect()) {
      ledSolid();
      Serial.println("[wolf] Ready — waiting for commands");
    } else {
      ledFastBlink();
      Serial.println("[wolf] MQTT connect failed — will retry in loop");
    }

  } else if (configNeedsPairing()) {
    // Has WiFi + token but no MQTT config — poll for pairing
    Serial.println("[wolf] Registered but not paired — entering polling mode");

    EEPROM.begin(EEPROM_SIZE);
    _wolfConfig.wifiSsid    = _eepromReadString(ADDR_WIFI_SSID);
    _wolfConfig.wifiPassword = _eepromReadString(ADDR_WIFI_PASS);
    _wolfConfig.deviceToken  = _eepromReadString(ADDR_DEVICE_TOKEN);
    EEPROM.end();

    if (!configConnectWifi()) {
      Serial.println("[wolf] WiFi failed — entering setup mode");
      currentMode = MODE_SETUP;
      configClear();
      configStartPortal();
      return;
    }

    currentMode = MODE_POLLING;
    ledSlowBlink();

  } else if (configNeedsRegistration()) {
    // Has WiFi but no token — needs to register
    Serial.println("[wolf] WiFi config found — need to register");

    EEPROM.begin(EEPROM_SIZE);
    _wolfConfig.wifiSsid    = _eepromReadString(ADDR_WIFI_SSID);
    _wolfConfig.wifiPassword = _eepromReadString(ADDR_WIFI_PASS);
    EEPROM.end();

    if (!configConnectWifi()) {
      Serial.println("[wolf] WiFi failed — entering setup mode");
      currentMode = MODE_SETUP;
      configClear();
      configStartPortal();
      return;
    }

    currentMode = MODE_REGISTER;

  } else {
    // No config at all
    Serial.println("[wolf] No config found — entering setup mode");
    currentMode = MODE_SETUP;
    configStartPortal();
  }
}

void loop() {
  switch (currentMode) {
    case MODE_SETUP:
      configPortalLoop();
      break;

    case MODE_REGISTER:
      Serial.println("[wolf] Registering with backend...");
      if (configRegister()) {
        Serial.println("[wolf] Registration successful — entering polling mode");
        currentMode = MODE_POLLING;
        ledSlowBlink();
        lastPoll = 0; // poll immediately
      } else {
        Serial.println("[wolf] Registration failed — retrying in 10s");
        delay(10000);
      }
      break;

    case MODE_POLLING: {
      unsigned long now = millis();
      if (now - lastPoll >= POLL_INTERVAL || lastPoll == 0) {
        lastPoll = now;
        Serial.println("[wolf] Polling for pairing status...");
        int result = configPollStatus();
        if (result == 1) {
          Serial.println("[wolf] Paired! Entering normal mode");
          currentMode = MODE_NORMAL;
          servoSetup();
          if (mqttConnect()) {
            ledSolid();
            Serial.println("[wolf] Ready — waiting for commands");
          } else {
            ledFastBlink();
            Serial.println("[wolf] MQTT connect failed — will retry in loop");
          }
        } else if (result == 0) {
          Serial.println("[wolf] Not paired yet — waiting...");
        }
        // result == -1: error, will retry next interval
      }
      ledUpdate();
      break;
    }

    case MODE_NORMAL:
      if (WiFi.status() != WL_CONNECTED) {
        Serial.println("[wolf] WiFi lost — reconnecting");
        ledFastBlink();
        configConnectWifi();
      }
      mqttLoop();
      ledUpdate();
      break;
  }
}
```

- [ ] **Step 10: Update mqtt.h — use hardcoded device ID for topics**

In `mqtt.h`, update the `mqttConnect` function. Replace these lines:

```cpp
  WolfConfig& cfg = configGet();

  _commandTopic = "wolf/" + cfg.deviceId + "/command";
  _statusTopic  = "wolf/" + cfg.deviceId + "/status";

  String clientId = "wolf-" + cfg.deviceId;
```

With:

```cpp
  WolfConfig& cfg = configGet();

  _commandTopic = "wolf/" + String(WOLF_DEVICE_ID) + "/command";
  _statusTopic  = "wolf/" + String(WOLF_DEVICE_ID) + "/status";

  String clientId = "wolf-" + String(WOLF_DEVICE_ID);
```

Also update the heartbeat function. Replace:

```cpp
  String topic = "wolf/" + configGet().deviceId + "/heartbeat";
```

With:

```cpp
  String topic = "wolf/" + String(WOLF_DEVICE_ID) + "/heartbeat";
```

- [ ] **Step 11: Update servo.h — use hardcoded device ID**

In `servo.h`, in the `servoTrigger` function, replace:

```cpp
  WolfConfig& cfg = configGet();
  String topic = "wolf/" + cfg.deviceId + "/servo";
```

With:

```cpp
  String topic = "wolf/" + String(WOLF_DEVICE_ID) + "/servo";
```

- [ ] **Step 12: Verify firmware compiles**

Open in Arduino IDE and verify it compiles without errors for NodeMCU board.

- [ ] **Step 13: Commit (backend files only — firmware is outside the git repo)**

No git commit for firmware files since they live in `Documents/Arduino/`, outside the wolf repo.

---

### Task 9: Add Navigation Link to Claim Device

**Files:**
- Modify: `resources/js/Layouts/AuthenticatedLayout.tsx`

- [ ] **Step 1: Add "Claim Device" link to the navigation**

In `resources/js/Layouts/AuthenticatedLayout.tsx`, find where the "Dashboard" nav link is rendered and add a "Claim Device" link after it. Look for the pattern that renders `NavLink` components and add:

```tsx
<NavLink href={route('devices.claim')} active={route().current('devices.claim')}>
    Claim Device
</NavLink>
```

- [ ] **Step 2: Run all tests**

Run: `docker compose exec app php artisan test`
Expected: All tests PASS.

- [ ] **Step 3: Commit**

```bash
git add resources/js/Layouts/AuthenticatedLayout.tsx
git commit -m "feat: add Claim Device link to navigation"
```

---

### Task 10: Final Integration Test

**Files:**
- No new files — run existing tests and verify full flow

- [ ] **Step 1: Run the full test suite**

Run: `docker compose exec app php artisan test`
Expected: All tests PASS.

- [ ] **Step 2: Manual integration test checklist**

Verify the following manually in the dev environment:

1. `POST /api/device/register` with `{"device_id":"ESP8266-TEST","type":"esp8266","name":"ESP8266-TEST"}` returns 201 with token
2. `GET /api/device/ESP8266-TEST/status` with Bearer token returns `{"paired":false}`
3. Log in as a regular user, go to `/devices/claim`, enter `ESP8266-TEST` — device gets linked
4. `GET /api/device/ESP8266-TEST/status` with Bearer token now returns `{"paired":true,"mqtt_host":"...","mqtt_port":1883}`
5. Dashboard shows the claimed device with appropriate controls
6. Admin can see the device in `/devices` and can unclaim/reassign it
7. Dashboard shows "Claim a Device" link when user has no devices

- [ ] **Step 3: Verify old provisioning endpoint is gone**

Run: `curl -s http://localhost:8000/api/device/test/provision` — should return 404.

- [ ] **Step 4: Commit any remaining fixes**

If any test failures or issues were found and fixed, commit them.
