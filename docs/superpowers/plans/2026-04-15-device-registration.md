# Device Registration (Admin-Only) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let admin users create, view, edit, and delete ESP32-CAM devices and assign them to users via a dedicated Devices page.

**Architecture:** Add `is_admin` boolean to users, an admin middleware guard, a resource controller for devices, and three Inertia/React pages (index, create, edit) behind the admin gate. Token generation is surfaced via a flash banner after create/regenerate.

**Tech Stack:** Laravel 13, Inertia.js, React 18, TypeScript, Tailwind CSS, PHPUnit

---

## File Map

| Action | File | Responsibility |
|--------|------|----------------|
| Create | `database/migrations/2026_04_15_000001_add_is_admin_to_users_table.php` | Add `is_admin` boolean to users |
| Create | `database/factories/DeviceFactory.php` | Factory for Device model in tests |
| Modify | `database/factories/UserFactory.php` | Add `admin()` state method |
| Create | `app/Http/Middleware/AdminMiddleware.php` | 403 if not admin |
| Modify | `bootstrap/app.php` | Register admin middleware alias |
| Create | `app/Http/Controllers/DeviceController.php` | Resource controller + regenerateToken |
| Create | `app/Http/Requests/StoreDeviceRequest.php` | Validation for device creation |
| Create | `app/Http/Requests/UpdateDeviceRequest.php` | Validation for device updates |
| Modify | `routes/web.php` | Device resource routes with admin middleware |
| Modify | `app/Http/Middleware/HandleInertiaRequests.php` | Share `device_token` flash prop |
| Modify | `resources/js/types/index.d.ts` | Add `Device` interface, `is_admin` to `User`, `device_token` to flash |
| Create | `resources/js/Components/DeviceTokenBanner.tsx` | Amber banner with copy-to-clipboard |
| Create | `resources/js/Components/DeviceStatusBadge.tsx` | Online/offline pill badge |
| Create | `resources/js/Pages/Devices/Index.tsx` | Device list table page |
| Create | `resources/js/Pages/Devices/Create.tsx` | Device creation form page |
| Create | `resources/js/Pages/Devices/Edit.tsx` | Device edit form page |
| Modify | `resources/js/Layouts/AuthenticatedLayout.tsx` | Add conditional "Devices" nav link |
| Create | `tests/Feature/DeviceManagementTest.php` | Feature tests for all device CRUD |

---

### Task 1: Migration — Add `is_admin` to Users

**Files:**
- Create: `database/migrations/2026_04_15_000001_add_is_admin_to_users_table.php`

- [ ] **Step 1: Create migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_admin')->default(false)->after('email_verified_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_admin');
        });
    }
};
```

- [ ] **Step 2: Run migration**

Run: `cd /Users/mr.casanova/Code/wolf && php artisan migrate`
Expected: "DONE" with the new migration applied.

- [ ] **Step 3: Commit**

```bash
git add database/migrations/2026_04_15_000001_add_is_admin_to_users_table.php
git commit -m "feat: add is_admin column to users table"
```

---

### Task 2: User Factory — Add `admin()` State

**Files:**
- Modify: `database/factories/UserFactory.php`

- [ ] **Step 1: Add admin state method to UserFactory**

Add after the `unverified()` method:

```php
public function admin(): static
{
    return $this->state(fn (array $attributes) => [
        'is_admin' => true,
    ]);
}
```

- [ ] **Step 2: Commit**

```bash
git add database/factories/UserFactory.php
git commit -m "feat: add admin() state to UserFactory"
```

---

### Task 3: Device Factory

**Files:**
- Create: `database/factories/DeviceFactory.php`

- [ ] **Step 1: Create DeviceFactory**

```php
<?php

namespace Database\Factories;

use App\Models\Device;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends Factory<Device>
 */
class DeviceFactory extends Factory
{
    protected $model = Device::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->words(2, true) . ' cam',
            'device_id' => 'esp32-' . fake()->unique()->numerify('###'),
            'token_hash' => Hash::make('test-device-token'),
            'type' => 'esp32-cam',
            'is_online' => false,
            'last_seen_at' => null,
            'meta' => null,
        ];
    }

    public function online(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_online' => true,
            'last_seen_at' => now(),
        ]);
    }
}
```

- [ ] **Step 2: Add HasFactory trait to Device model if missing**

Check `app/Models/Device.php` — if `HasFactory` is not already in the `use` statement, add it:

```php
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Device extends Model
{
    use HasFactory;
    // ...
}
```

- [ ] **Step 3: Commit**

```bash
git add database/factories/DeviceFactory.php app/Models/Device.php
git commit -m "feat: add DeviceFactory with online state"
```

---

### Task 4: Admin Middleware

**Files:**
- Create: `app/Http/Middleware/AdminMiddleware.php`
- Modify: `bootstrap/app.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/DeviceManagementTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeviceManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_non_admin_cannot_access_devices_index(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/devices');

        $response->assertForbidden();
    }

    public function test_admin_can_access_devices_index(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->get('/devices');

        $response->assertOk();
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `cd /Users/mr.casanova/Code/wolf && php artisan test --filter=DeviceManagementTest`
Expected: FAIL — routes and middleware don't exist yet.

- [ ] **Step 3: Create AdminMiddleware**

```php
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AdminMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->is_admin) {
            abort(403);
        }

        return $next($request);
    }
}
```

- [ ] **Step 4: Register middleware alias in bootstrap/app.php**

In the `->withMiddleware()` callback, add the alias registration after the existing `web(append: ...)` call:

```php
->withMiddleware(function (Middleware $middleware): void {
    $middleware->web(append: [
        \App\Http\Middleware\HandleInertiaRequests::class,
        \Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets::class,
    ]);

    $middleware->alias([
        'admin' => \App\Http\Middleware\AdminMiddleware::class,
    ]);
})
```

- [ ] **Step 5: Commit**

```bash
git add app/Http/Middleware/AdminMiddleware.php bootstrap/app.php tests/Feature/DeviceManagementTest.php
git commit -m "feat: add admin middleware with route alias"
```

---

### Task 5: Device Controller + Routes

**Files:**
- Create: `app/Http/Controllers/DeviceController.php`
- Create: `app/Http/Requests/StoreDeviceRequest.php`
- Create: `app/Http/Requests/UpdateDeviceRequest.php`
- Modify: `routes/web.php`

- [ ] **Step 1: Add CRUD tests to DeviceManagementTest**

Append to `tests/Feature/DeviceManagementTest.php`:

```php
public function test_admin_can_create_device(): void
{
    $admin = User::factory()->admin()->create();
    $user = User::factory()->create();

    $response = $this->actingAs($admin)->post('/devices', [
        'name' => 'Front Door Cam',
        'device_id' => 'esp32-001',
        'user_id' => $user->id,
        'type' => 'esp32-cam',
    ]);

    $response->assertRedirect('/devices');
    $response->assertSessionHas('device_token');
    $this->assertDatabaseHas('devices', [
        'name' => 'Front Door Cam',
        'device_id' => 'esp32-001',
        'user_id' => $user->id,
    ]);
}

public function test_cannot_create_device_for_user_who_already_has_one(): void
{
    $admin = User::factory()->admin()->create();
    $user = User::factory()->create();
    \App\Models\Device::factory()->create(['user_id' => $user->id]);

    $response = $this->actingAs($admin)->post('/devices', [
        'name' => 'Second Cam',
        'device_id' => 'esp32-002',
        'user_id' => $user->id,
        'type' => 'esp32-cam',
    ]);

    $response->assertSessionHasErrors('user_id');
}

public function test_admin_can_update_device(): void
{
    $admin = User::factory()->admin()->create();
    $user = User::factory()->create();
    $device = \App\Models\Device::factory()->create(['user_id' => $user->id]);

    $response = $this->actingAs($admin)->put("/devices/{$device->id}", [
        'name' => 'Updated Name',
        'device_id' => $device->device_id,
        'user_id' => $user->id,
        'type' => 'esp32-cam',
    ]);

    $response->assertRedirect('/devices');
    $this->assertDatabaseHas('devices', ['name' => 'Updated Name']);
}

public function test_admin_can_delete_device(): void
{
    $admin = User::factory()->admin()->create();
    $device = \App\Models\Device::factory()->create();

    $response = $this->actingAs($admin)->delete("/devices/{$device->id}");

    $response->assertRedirect('/devices');
    $this->assertDatabaseMissing('devices', ['id' => $device->id]);
}

public function test_admin_can_regenerate_device_token(): void
{
    $admin = User::factory()->admin()->create();
    $device = \App\Models\Device::factory()->create();
    $oldHash = $device->token_hash;

    $response = $this->actingAs($admin)->post("/devices/{$device->id}/regenerate-token");

    $response->assertRedirect();
    $response->assertSessionHas('device_token');
    $this->assertNotEquals($oldHash, $device->fresh()->token_hash);
}

public function test_device_id_must_be_unique(): void
{
    $admin = User::factory()->admin()->create();
    $user1 = User::factory()->create();
    $user2 = User::factory()->create();
    \App\Models\Device::factory()->create(['device_id' => 'esp32-001', 'user_id' => $user1->id]);

    $response = $this->actingAs($admin)->post('/devices', [
        'name' => 'Another Cam',
        'device_id' => 'esp32-001',
        'user_id' => $user2->id,
        'type' => 'esp32-cam',
    ]);

    $response->assertSessionHasErrors('device_id');
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `cd /Users/mr.casanova/Code/wolf && php artisan test --filter=DeviceManagementTest`
Expected: FAIL — controller and routes don't exist yet.

- [ ] **Step 3: Create StoreDeviceRequest**

```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

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
            'user_id' => ['required', 'exists:users,id', 'unique:devices,user_id'],
            'type' => ['sometimes', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'user_id.unique' => 'This user already has a device assigned.',
        ];
    }
}
```

- [ ] **Step 4: Create UpdateDeviceRequest**

```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

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
            'user_id' => ['required', 'exists:users,id', "unique:devices,user_id,{$deviceId}"],
            'type' => ['sometimes', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'user_id.unique' => 'This user already has a device assigned.',
        ];
    }
}
```

- [ ] **Step 5: Create DeviceController**

```php
<?php

namespace App\Http\Controllers;

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
        $availableUsers = User::whereDoesntHave('devices')
            ->select('id', 'name', 'email')
            ->orderBy('name')
            ->get();

        return Inertia::render('Devices/Create', [
            'users' => $availableUsers,
        ]);
    }

    public function store(StoreDeviceRequest $request)
    {
        $device = Device::create([
            'name' => $request->name,
            'device_id' => $request->device_id,
            'user_id' => $request->user_id,
            'type' => $request->type ?? 'esp32-cam',
            'token_hash' => '',
        ]);

        $token = $device->generateToken();

        return redirect()->route('devices.index')->with('device_token', $token);
    }

    public function edit(Device $device)
    {
        $device->load('user');

        $availableUsers = User::where(function ($query) use ($device) {
            $query->whereDoesntHave('devices')
                  ->orWhere('id', $device->user_id);
        })
            ->select('id', 'name', 'email')
            ->orderBy('name')
            ->get();

        return Inertia::render('Devices/Edit', [
            'device' => $device,
            'users' => $availableUsers,
        ]);
    }

    public function update(UpdateDeviceRequest $request, Device $device)
    {
        $device->update([
            'name' => $request->name,
            'device_id' => $request->device_id,
            'user_id' => $request->user_id,
            'type' => $request->type ?? $device->type,
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

- [ ] **Step 6: Add routes to web.php**

Add inside the `['auth', 'verified']` middleware group, after the existing device capture route:

```php
Route::middleware('admin')->group(function () {
    Route::resource('devices', \App\Http\Controllers\DeviceController::class)
        ->except(['show']);
    Route::post('/devices/{device}/regenerate-token', [\App\Http\Controllers\DeviceController::class, 'regenerateToken'])
        ->name('devices.regenerate-token');
});
```

- [ ] **Step 7: Run tests to verify they pass**

Run: `cd /Users/mr.casanova/Code/wolf && php artisan test --filter=DeviceManagementTest`
Expected: All 8 tests PASS.

- [ ] **Step 8: Commit**

```bash
git add app/Http/Controllers/DeviceController.php app/Http/Requests/StoreDeviceRequest.php app/Http/Requests/UpdateDeviceRequest.php routes/web.php tests/Feature/DeviceManagementTest.php
git commit -m "feat: device CRUD controller with admin-only routes and tests"
```

---

### Task 6: Share `device_token` Flash Prop via Inertia

**Files:**
- Modify: `app/Http/Middleware/HandleInertiaRequests.php`

- [ ] **Step 1: Add `device_token` to shared flash props**

In the `share()` method, update the `flash` array:

```php
'flash' => [
    'capture' => $request->session()->get('capture'),
    'device_token' => $request->session()->get('device_token'),
],
```

- [ ] **Step 2: Commit**

```bash
git add app/Http/Middleware/HandleInertiaRequests.php
git commit -m "feat: share device_token flash prop via Inertia"
```

---

### Task 7: TypeScript Types

**Files:**
- Modify: `resources/js/types/index.d.ts`

- [ ] **Step 1: Update types**

Replace the full contents of the file:

```typescript
export interface User {
    id: number;
    name: string;
    email: string;
    email_verified_at?: string;
    is_admin: boolean;
}

export interface Device {
    id: number;
    user_id: number;
    name: string;
    device_id: string;
    type: string;
    is_online: boolean;
    last_seen_at: string | null;
    meta: Record<string, unknown> | null;
    user?: User;
}

export interface CaptureData {
    id: number;
    trigger_source: string;
    media_type: 'image' | 'video';
    media_url: string | null;
    status: 'pending' | 'success' | 'failed';
    error_message: string | null;
    captured_at: string;
}

export type PageProps<T = {}> = T & {
    auth: { user: User };
    flash: {
        capture?: CaptureData;
        device_token?: string;
    };
};
```

- [ ] **Step 2: Commit**

```bash
git add resources/js/types/index.d.ts
git commit -m "feat: add Device type and is_admin to User type"
```

---

### Task 8: DeviceTokenBanner Component

**Files:**
- Create: `resources/js/Components/DeviceTokenBanner.tsx`

- [ ] **Step 1: Create the component**

```tsx
import { useState } from 'react';

export default function DeviceTokenBanner({ token }: { token: string }) {
    const [copied, setCopied] = useState(false);

    const copyToClipboard = () => {
        navigator.clipboard.writeText(token);
        setCopied(true);
        setTimeout(() => setCopied(false), 2000);
    };

    return (
        <div className="mb-6 rounded-md border border-amber-300 bg-amber-50 p-4">
            <h3 className="text-sm font-semibold text-amber-800">
                Device Token Generated
            </h3>
            <p className="mt-1 text-sm text-amber-700">
                Copy this token now. It will not be shown again.
            </p>
            <div className="mt-3 flex items-center gap-3">
                <code className="flex-1 rounded bg-amber-100 px-3 py-2 font-mono text-sm text-amber-900 break-all">
                    {token}
                </code>
                <button
                    type="button"
                    onClick={copyToClipboard}
                    className="shrink-0 rounded-md bg-amber-600 px-3 py-2 text-sm font-medium text-white hover:bg-amber-500 focus:outline-none focus:ring-2 focus:ring-amber-500 focus:ring-offset-2"
                >
                    {copied ? 'Copied!' : 'Copy'}
                </button>
            </div>
        </div>
    );
}
```

- [ ] **Step 2: Commit**

```bash
git add resources/js/Components/DeviceTokenBanner.tsx
git commit -m "feat: add DeviceTokenBanner component with clipboard copy"
```

---

### Task 9: DeviceStatusBadge Component

**Files:**
- Create: `resources/js/Components/DeviceStatusBadge.tsx`

- [ ] **Step 1: Create the component**

```tsx
export default function DeviceStatusBadge({ isOnline }: { isOnline: boolean }) {
    return isOnline ? (
        <span className="inline-flex items-center rounded-full bg-green-100 px-2.5 py-0.5 text-xs font-medium text-green-800">
            Online
        </span>
    ) : (
        <span className="inline-flex items-center rounded-full bg-gray-100 px-2.5 py-0.5 text-xs font-medium text-gray-800">
            Offline
        </span>
    );
}
```

- [ ] **Step 2: Commit**

```bash
git add resources/js/Components/DeviceStatusBadge.tsx
git commit -m "feat: add DeviceStatusBadge component"
```

---

### Task 10: Devices Index Page

**Files:**
- Create: `resources/js/Pages/Devices/Index.tsx`

- [ ] **Step 1: Create the page**

```tsx
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import DeviceStatusBadge from '@/Components/DeviceStatusBadge';
import DeviceTokenBanner from '@/Components/DeviceTokenBanner';
import DangerButton from '@/Components/DangerButton';
import Modal from '@/Components/Modal';
import SecondaryButton from '@/Components/SecondaryButton';
import { Device, PageProps } from '@/types';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';

export default function Index({ devices }: PageProps<{ devices: Device[] }>) {
    const { flash } = usePage<PageProps>().props;
    const [confirmingDeviceDeletion, setConfirmingDeviceDeletion] = useState<number | null>(null);

    const { delete: destroy, processing } = useForm({});

    const confirmDeviceDeletion = (deviceId: number) => {
        setConfirmingDeviceDeletion(deviceId);
    };

    const deleteDevice = () => {
        if (confirmingDeviceDeletion === null) return;

        destroy(route('devices.destroy', confirmingDeviceDeletion), {
            preserveScroll: true,
            onSuccess: () => setConfirmingDeviceDeletion(null),
        });
    };

    const closeModal = () => {
        setConfirmingDeviceDeletion(null);
    };

    return (
        <AuthenticatedLayout
            header={
                <div className="flex items-center justify-between">
                    <h2 className="text-xl font-semibold leading-tight text-gray-800">
                        Devices
                    </h2>
                    <Link
                        href={route('devices.create')}
                        className="inline-flex items-center rounded-md border border-transparent bg-gray-800 px-4 py-2 text-xs font-semibold uppercase tracking-widest text-white transition duration-150 ease-in-out hover:bg-gray-700 focus:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 active:bg-gray-900"
                    >
                        Add Device
                    </Link>
                </div>
            }
        >
            <Head title="Devices" />

            <div className="py-12">
                <div className="mx-auto max-w-7xl sm:px-6 lg:px-8">
                    {flash.device_token && (
                        <DeviceTokenBanner token={flash.device_token} />
                    )}

                    <div className="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                        <div className="overflow-x-auto">
                            <table className="min-w-full divide-y divide-gray-200">
                                <thead className="bg-gray-50">
                                    <tr>
                                        <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Name</th>
                                        <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Device ID</th>
                                        <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Assigned User</th>
                                        <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Type</th>
                                        <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Status</th>
                                        <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Last Seen</th>
                                        <th className="px-6 py-3 text-right text-xs font-medium uppercase tracking-wider text-gray-500">Actions</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-gray-200 bg-white">
                                    {devices.length === 0 && (
                                        <tr>
                                            <td colSpan={7} className="px-6 py-8 text-center text-sm text-gray-500">
                                                No devices registered yet.
                                            </td>
                                        </tr>
                                    )}
                                    {devices.map((device) => (
                                        <tr key={device.id}>
                                            <td className="whitespace-nowrap px-6 py-4 text-sm font-medium text-gray-900">
                                                {device.name}
                                            </td>
                                            <td className="whitespace-nowrap px-6 py-4 font-mono text-sm text-gray-500">
                                                {device.device_id}
                                            </td>
                                            <td className="whitespace-nowrap px-6 py-4 text-sm text-gray-500">
                                                {device.user ? (
                                                    <div>
                                                        <div>{device.user.name}</div>
                                                        <div className="text-xs text-gray-400">{device.user.email}</div>
                                                    </div>
                                                ) : (
                                                    <span className="text-gray-400">—</span>
                                                )}
                                            </td>
                                            <td className="whitespace-nowrap px-6 py-4 text-sm text-gray-500">
                                                {device.type}
                                            </td>
                                            <td className="whitespace-nowrap px-6 py-4">
                                                <DeviceStatusBadge isOnline={device.is_online} />
                                            </td>
                                            <td className="whitespace-nowrap px-6 py-4 text-sm text-gray-500">
                                                {device.last_seen_at ?? 'Never'}
                                            </td>
                                            <td className="whitespace-nowrap px-6 py-4 text-right text-sm">
                                                <Link
                                                    href={route('devices.edit', device.id)}
                                                    className="text-indigo-600 hover:text-indigo-900"
                                                >
                                                    Edit
                                                </Link>
                                                <button
                                                    type="button"
                                                    onClick={() => confirmDeviceDeletion(device.id)}
                                                    className="ml-4 text-red-600 hover:text-red-900"
                                                >
                                                    Delete
                                                </button>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <Modal show={confirmingDeviceDeletion !== null} onClose={closeModal}>
                <div className="p-6">
                    <h2 className="text-lg font-medium text-gray-900">
                        Are you sure you want to delete this device?
                    </h2>
                    <p className="mt-1 text-sm text-gray-600">
                        This will permanently remove the device and invalidate its token. Any captures linked to this device will remain but the device will no longer be able to upload new media.
                    </p>
                    <div className="mt-6 flex justify-end">
                        <SecondaryButton onClick={closeModal}>Cancel</SecondaryButton>
                        <DangerButton className="ms-3" disabled={processing} onClick={deleteDevice}>
                            Delete Device
                        </DangerButton>
                    </div>
                </div>
            </Modal>
        </AuthenticatedLayout>
    );
}
```

- [ ] **Step 2: Commit**

```bash
git add resources/js/Pages/Devices/Index.tsx
git commit -m "feat: add Devices index page with table, delete modal, and token banner"
```

---

### Task 11: Devices Create Page

**Files:**
- Create: `resources/js/Pages/Devices/Create.tsx`

- [ ] **Step 1: Create the page**

```tsx
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import TextInput from '@/Components/TextInput';
import { PageProps, User } from '@/types';
import { Head, Link, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';

export default function Create({ users }: PageProps<{ users: Pick<User, 'id' | 'name' | 'email'>[] }>) {
    const { data, setData, post, processing, errors } = useForm({
        name: '',
        device_id: '',
        user_id: '',
        type: 'esp32-cam',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('devices.store'));
    };

    return (
        <AuthenticatedLayout
            header={
                <h2 className="text-xl font-semibold leading-tight text-gray-800">
                    Add Device
                </h2>
            }
        >
            <Head title="Add Device" />

            <div className="py-12">
                <div className="mx-auto max-w-2xl sm:px-6 lg:px-8">
                    <div className="bg-white p-6 shadow-sm sm:rounded-lg">
                        <form onSubmit={submit} className="space-y-6">
                            <div>
                                <InputLabel htmlFor="name" value="Device Name" />
                                <TextInput
                                    id="name"
                                    value={data.name}
                                    onChange={(e) => setData('name', e.target.value)}
                                    className="mt-1 block w-full"
                                    required
                                    isFocused
                                    placeholder="e.g. Front Door Cam"
                                />
                                <InputError message={errors.name} className="mt-2" />
                            </div>

                            <div>
                                <InputLabel htmlFor="device_id" value="Device ID" />
                                <TextInput
                                    id="device_id"
                                    value={data.device_id}
                                    onChange={(e) => setData('device_id', e.target.value)}
                                    className="mt-1 block w-full"
                                    required
                                    placeholder="e.g. esp32-001"
                                />
                                <InputError message={errors.device_id} className="mt-2" />
                            </div>

                            <div>
                                <InputLabel htmlFor="user_id" value="Assign to User" />
                                <select
                                    id="user_id"
                                    value={data.user_id}
                                    onChange={(e) => setData('user_id', e.target.value)}
                                    className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                    required
                                >
                                    <option value="">Select a user...</option>
                                    {users.map((user) => (
                                        <option key={user.id} value={user.id}>
                                            {user.name} ({user.email})
                                        </option>
                                    ))}
                                </select>
                                <InputError message={errors.user_id} className="mt-2" />
                            </div>

                            <div>
                                <InputLabel htmlFor="type" value="Device Type" />
                                <TextInput
                                    id="type"
                                    value={data.type}
                                    onChange={(e) => setData('type', e.target.value)}
                                    className="mt-1 block w-full"
                                />
                                <InputError message={errors.type} className="mt-2" />
                            </div>

                            <div className="flex items-center justify-end gap-4">
                                <Link
                                    href={route('devices.index')}
                                    className="text-sm text-gray-600 underline hover:text-gray-900"
                                >
                                    Cancel
                                </Link>
                                <PrimaryButton disabled={processing}>
                                    Create Device
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

- [ ] **Step 2: Commit**

```bash
git add resources/js/Pages/Devices/Create.tsx
git commit -m "feat: add Devices create page with form"
```

---

### Task 12: Devices Edit Page

**Files:**
- Create: `resources/js/Pages/Devices/Edit.tsx`

- [ ] **Step 1: Create the page**

```tsx
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import DeviceTokenBanner from '@/Components/DeviceTokenBanner';
import DangerButton from '@/Components/DangerButton';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import Modal from '@/Components/Modal';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import TextInput from '@/Components/TextInput';
import { Device, PageProps, User } from '@/types';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';

export default function Edit({
    device,
    users,
}: PageProps<{ device: Device; users: Pick<User, 'id' | 'name' | 'email'>[] }>) {
    const { flash } = usePage<PageProps>().props;
    const [confirmingTokenRegeneration, setConfirmingTokenRegeneration] = useState(false);

    const { data, setData, put, processing, errors } = useForm({
        name: device.name,
        device_id: device.device_id,
        user_id: String(device.user_id),
        type: device.type,
    });

    const { post: regenerate, processing: regenerating } = useForm({});

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        put(route('devices.update', device.id));
    };

    const confirmTokenRegeneration = () => {
        setConfirmingTokenRegeneration(true);
    };

    const regenerateToken = () => {
        regenerate(route('devices.regenerate-token', device.id), {
            preserveScroll: true,
            onSuccess: () => setConfirmingTokenRegeneration(false),
        });
    };

    const closeModal = () => {
        setConfirmingTokenRegeneration(false);
    };

    return (
        <AuthenticatedLayout
            header={
                <h2 className="text-xl font-semibold leading-tight text-gray-800">
                    Edit Device
                </h2>
            }
        >
            <Head title="Edit Device" />

            <div className="py-12">
                <div className="mx-auto max-w-2xl sm:px-6 lg:px-8">
                    {flash.device_token && (
                        <DeviceTokenBanner token={flash.device_token} />
                    )}

                    <div className="bg-white p-6 shadow-sm sm:rounded-lg">
                        <form onSubmit={submit} className="space-y-6">
                            <div>
                                <InputLabel htmlFor="name" value="Device Name" />
                                <TextInput
                                    id="name"
                                    value={data.name}
                                    onChange={(e) => setData('name', e.target.value)}
                                    className="mt-1 block w-full"
                                    required
                                    isFocused
                                />
                                <InputError message={errors.name} className="mt-2" />
                            </div>

                            <div>
                                <InputLabel htmlFor="device_id" value="Device ID" />
                                <TextInput
                                    id="device_id"
                                    value={data.device_id}
                                    onChange={(e) => setData('device_id', e.target.value)}
                                    className="mt-1 block w-full"
                                    required
                                />
                                <InputError message={errors.device_id} className="mt-2" />
                            </div>

                            <div>
                                <InputLabel htmlFor="user_id" value="Assign to User" />
                                <select
                                    id="user_id"
                                    value={data.user_id}
                                    onChange={(e) => setData('user_id', e.target.value)}
                                    className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                    required
                                >
                                    <option value="">Select a user...</option>
                                    {users.map((user) => (
                                        <option key={user.id} value={user.id}>
                                            {user.name} ({user.email})
                                        </option>
                                    ))}
                                </select>
                                <InputError message={errors.user_id} className="mt-2" />
                            </div>

                            <div>
                                <InputLabel htmlFor="type" value="Device Type" />
                                <TextInput
                                    id="type"
                                    value={data.type}
                                    onChange={(e) => setData('type', e.target.value)}
                                    className="mt-1 block w-full"
                                />
                                <InputError message={errors.type} className="mt-2" />
                            </div>

                            <div className="flex items-center justify-end gap-4">
                                <Link
                                    href={route('devices.index')}
                                    className="text-sm text-gray-600 underline hover:text-gray-900"
                                >
                                    Cancel
                                </Link>
                                <PrimaryButton disabled={processing}>
                                    Update Device
                                </PrimaryButton>
                            </div>
                        </form>
                    </div>

                    <div className="mt-6 bg-white p-6 shadow-sm sm:rounded-lg">
                        <h3 className="text-lg font-medium text-gray-900">Device Token</h3>
                        <p className="mt-1 text-sm text-gray-600">
                            Regenerate the device token if the original was lost or compromised. This will invalidate the previous token.
                        </p>
                        <div className="mt-4">
                            <DangerButton onClick={confirmTokenRegeneration}>
                                Regenerate Token
                            </DangerButton>
                        </div>
                    </div>
                </div>
            </div>

            <Modal show={confirmingTokenRegeneration} onClose={closeModal}>
                <div className="p-6">
                    <h2 className="text-lg font-medium text-gray-900">
                        Regenerate device token?
                    </h2>
                    <p className="mt-1 text-sm text-gray-600">
                        The current token will stop working immediately. You will need to update the token on the physical device.
                    </p>
                    <div className="mt-6 flex justify-end">
                        <SecondaryButton onClick={closeModal}>Cancel</SecondaryButton>
                        <DangerButton className="ms-3" disabled={regenerating} onClick={regenerateToken}>
                            Regenerate Token
                        </DangerButton>
                    </div>
                </div>
            </Modal>
        </AuthenticatedLayout>
    );
}
```

- [ ] **Step 2: Commit**

```bash
git add resources/js/Pages/Devices/Edit.tsx
git commit -m "feat: add Devices edit page with token regeneration"
```

---

### Task 13: Add Devices Nav Link to AuthenticatedLayout

**Files:**
- Modify: `resources/js/Layouts/AuthenticatedLayout.tsx`

- [ ] **Step 1: Add conditional Devices nav link**

In the desktop navigation section, after the existing Dashboard `NavLink`, add:

```tsx
{user.is_admin && (
    <NavLink
        href={route('devices.index')}
        active={route().current('devices.*')}
    >
        Devices
    </NavLink>
)}
```

In the mobile/responsive navigation section, after the existing Dashboard `ResponsiveNavLink`, add:

```tsx
{user.is_admin && (
    <ResponsiveNavLink
        href={route('devices.index')}
        active={route().current('devices.*')}
    >
        Devices
    </ResponsiveNavLink>
)}
```

- [ ] **Step 2: Commit**

```bash
git add resources/js/Layouts/AuthenticatedLayout.tsx
git commit -m "feat: add conditional Devices nav link for admin users"
```

---

### Task 14: Run Full Test Suite

- [ ] **Step 1: Run all tests**

Run: `cd /Users/mr.casanova/Code/wolf && php artisan test`
Expected: All tests pass (existing 25 + new 8 = 33 tests).

- [ ] **Step 2: Build frontend to check for TypeScript errors**

Run: `cd /Users/mr.casanova/Code/wolf && nvm use v20.19.3 && npm run build`
Expected: Build succeeds with no errors.

- [ ] **Step 3: Fix any issues found, then commit if needed**

---
