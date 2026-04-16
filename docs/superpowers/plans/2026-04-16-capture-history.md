# Capture History Page — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a paginated Capture History page where users see their own captures and admins see all captures with a User column.

**Architecture:** New `CaptureHistoryController` renders an Inertia page. Regular users get their own captures via the existing `user->captures()` relationship; admins get all captures via `DeviceCapture::with(['user', 'device'])`. The existing `CaptureResource` is extended to include optional device and user data. A new `CaptureStatusBadge` component handles status display. Nav link added for all authenticated users.

**Tech Stack:** Laravel 13, Inertia.js, React 18, TypeScript, Tailwind CSS, PHPUnit

---

## File Map

| Action | File | Responsibility |
|--------|------|----------------|
| Create | `app/Http/Controllers/CaptureHistoryController.php` | Index method — role-aware paginated query |
| Modify | `app/Http/Resources/CaptureResource.php` | Add optional `device` and `user` fields |
| Modify | `routes/web.php` | Add `GET /captures` route |
| Modify | `resources/js/types/index.d.ts` | Add `PaginatedCaptures`, extend `CaptureData` |
| Create | `resources/js/Components/CaptureStatusBadge.tsx` | Pending/success/failed pill badge |
| Create | `resources/js/Pages/Captures/History.tsx` | Paginated history table page |
| Modify | `resources/js/Layouts/AuthenticatedLayout.tsx` | Add History nav link for all users |
| Create | `tests/Feature/CaptureHistoryTest.php` | Feature tests |

---

### Task 1: Extend CaptureResource with Device and User

**Files:**
- Modify: `app/Http/Resources/CaptureResource.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/CaptureHistoryTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\DeviceCapture;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CaptureHistoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_user_is_redirected(): void
    {
        $response = $this->get('/captures');

        $response->assertRedirect('/login');
    }

    public function test_user_can_access_capture_history(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/captures');

        $response->assertOk();
    }

    public function test_user_only_sees_their_own_captures(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $device = Device::factory()->create(['user_id' => $user->id]);
        $otherDevice = Device::factory()->create(['user_id' => $otherUser->id]);

        DeviceCapture::factory()->create(['user_id' => $user->id, 'device_id' => $device->id]);
        DeviceCapture::factory()->create(['user_id' => $otherUser->id, 'device_id' => $otherDevice->id]);

        $response = $this->actingAs($user)->get('/captures');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Captures/History')
            ->has('captures.data', 1)
        );
    }

    public function test_admin_sees_all_captures(): void
    {
        $admin = User::factory()->admin()->create();
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        $device1 = Device::factory()->create(['user_id' => $user1->id]);
        $device2 = Device::factory()->create(['user_id' => $user2->id]);

        DeviceCapture::factory()->create(['user_id' => $user1->id, 'device_id' => $device1->id]);
        DeviceCapture::factory()->create(['user_id' => $user2->id, 'device_id' => $device2->id]);

        $response = $this->actingAs($admin)->get('/captures');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Captures/History')
            ->has('captures.data', 2)
        );
    }

    public function test_is_admin_flag_is_passed_to_page(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->get('/captures');

        $response->assertInertia(fn ($page) => $page
            ->where('isAdmin', true)
        );
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `cd /Users/mr.casanova/Code/wolf && php artisan test --filter=CaptureHistoryTest`
Expected: FAIL — route, controller, and factory don't exist yet.

- [ ] **Step 3: Create DeviceCaptureFactory**

Create `database/factories/DeviceCaptureFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\Device;
use App\Models\DeviceCapture;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DeviceCapture>
 */
class DeviceCaptureFactory extends Factory
{
    protected $model = DeviceCapture::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'device_id' => Device::factory(),
            'trigger_source' => 'manual',
            'media_type' => 'image',
            'media_url' => null,
            'media_path' => null,
            'status' => 'pending',
            'error_message' => null,
            'device_meta' => null,
        ];
    }

    public function success(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'success',
            'media_url' => '/storage/captures/test.jpg',
            'media_path' => 'captures/test.jpg',
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'failed',
            'error_message' => 'Device did not respond.',
        ]);
    }
}
```

- [ ] **Step 4: Add HasFactory to DeviceCapture model**

In `app/Models/DeviceCapture.php`, add the import and trait:

```php
use Illuminate\Database\Eloquent\Factories\HasFactory;

class DeviceCapture extends Model
{
    use HasFactory;
    // ... rest of model unchanged
```

- [ ] **Step 5: Extend CaptureResource**

Replace the `toArray` method in `app/Http/Resources/CaptureResource.php`:

```php
public function toArray(Request $request): array
{
    return [
        'id'             => $this->id,
        'trigger_source' => $this->trigger_source,
        'media_type'     => $this->media_type,
        'media_url'      => $this->media_url,
        'status'         => $this->status,
        'error_message'  => $this->error_message,
        'captured_at'    => $this->created_at?->toISOString(),
        'device'         => $this->whenLoaded('device', fn () => [
            'name' => $this->device->name,
        ]),
        'user'           => $this->whenLoaded('user', fn () => [
            'name'  => $this->user->name,
            'email' => $this->user->email,
        ]),
    ];
}
```

---

### Task 2: CaptureHistoryController + Route

**Files:**
- Create: `app/Http/Controllers/CaptureHistoryController.php`
- Modify: `routes/web.php`

- [ ] **Step 1: Create the controller**

```php
<?php

namespace App\Http\Controllers;

use App\Http\Resources\CaptureResource;
use App\Models\DeviceCapture;
use Inertia\Inertia;
use Illuminate\Http\Request;

class CaptureHistoryController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        $captures = $user->is_admin
            ? DeviceCapture::with(['user', 'device'])->latest()->paginate(20)
            : $user->captures()->with('device')->latest()->paginate(20);

        return Inertia::render('Captures/History', [
            'captures' => CaptureResource::collection($captures),
            'isAdmin'  => $user->is_admin,
        ]);
    }
}
```

- [ ] **Step 2: Add route to web.php**

In `routes/web.php`, add inside the `['auth', 'verified']` middleware group, after the dashboard route:

```php
Route::get('/captures', [\App\Http\Controllers\CaptureHistoryController::class, 'index'])
    ->name('captures.index');
```

- [ ] **Step 3: Run tests to verify they pass**

Run: `cd /Users/mr.casanova/Code/wolf && php artisan test --filter=CaptureHistoryTest`
Expected: All 5 tests PASS.

- [ ] **Step 4: Run full suite to confirm no regressions**

Run: `cd /Users/mr.casanova/Code/wolf && php artisan test`
Expected: All 38 tests pass (33 existing + 5 new).

---

### Task 3: TypeScript Types

**Files:**
- Modify: `resources/js/types/index.d.ts`

- [ ] **Step 1: Extend CaptureData and add PaginatedCaptures**

Replace the full contents of `resources/js/types/index.d.ts`:

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
    device?: { name: string };
    user?: { name: string; email: string };
}

export interface PaginationLink {
    url: string | null;
    label: string;
    active: boolean;
}

export interface PaginatedCaptures {
    data: CaptureData[];
    links: PaginationLink[];
    meta: {
        current_page: number;
        last_page: number;
        total: number;
    };
}

export type PageProps<
    T extends Record<string, unknown> = Record<string, unknown>,
> = T & {
    auth: {
        user: User;
    };
    flash: {
        capture?: CaptureData;
        device_token?: string;
    };
};
```

---

### Task 4: CaptureStatusBadge Component

**Files:**
- Create: `resources/js/Components/CaptureStatusBadge.tsx`

- [ ] **Step 1: Create the component**

```tsx
export default function CaptureStatusBadge({
    status,
}: {
    status: 'pending' | 'success' | 'failed';
}) {
    if (status === 'success') {
        return (
            <span className="inline-flex items-center rounded-full bg-green-100 px-2.5 py-0.5 text-xs font-medium text-green-800">
                Success
            </span>
        );
    }

    if (status === 'failed') {
        return (
            <span className="inline-flex items-center rounded-full bg-red-100 px-2.5 py-0.5 text-xs font-medium text-red-800">
                Failed
            </span>
        );
    }

    return (
        <span className="inline-flex items-center rounded-full bg-yellow-100 px-2.5 py-0.5 text-xs font-medium text-yellow-800">
            Pending
        </span>
    );
}
```

---

### Task 5: Captures History Page

**Files:**
- Create: `resources/js/Pages/Captures/History.tsx`

- [ ] **Step 1: Create the directory and page**

```tsx
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import CaptureStatusBadge from '@/Components/CaptureStatusBadge';
import { CaptureData, PageProps, PaginatedCaptures } from '@/types';
import { Head, Link } from '@inertiajs/react';

function ThumbnailCell({ capture }: { capture: CaptureData }) {
    if (capture.status === 'success' && capture.media_type === 'image' && capture.media_url) {
        return (
            <img
                src={capture.media_url}
                alt="Capture thumbnail"
                className="h-10 w-10 rounded object-cover"
            />
        );
    }

    if (capture.status === 'success' && capture.media_type === 'video') {
        return (
            <div className="flex h-10 w-10 items-center justify-center rounded bg-gray-100">
                <svg className="h-5 w-5 text-gray-500" fill="currentColor" viewBox="0 0 20 20">
                    <path d="M2 6a2 2 0 012-2h6a2 2 0 012 2v8a2 2 0 01-2 2H4a2 2 0 01-2-2V6zm12.553 1.106A1 1 0 0014 8v4a1 1 0 00.553.894l2 1A1 1 0 0018 13V7a1 1 0 00-1.447-.894l-2 1z" />
                </svg>
            </div>
        );
    }

    if (capture.status === 'pending') {
        return (
            <div className="flex h-10 w-10 items-center justify-center rounded bg-gray-100">
                <svg className="h-5 w-5 animate-spin text-gray-400" fill="none" viewBox="0 0 24 24">
                    <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
                    <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z" />
                </svg>
            </div>
        );
    }

    return (
        <div className="flex h-10 w-10 items-center justify-center rounded bg-red-50">
            <svg className="h-5 w-5 text-red-400" fill="currentColor" viewBox="0 0 20 20">
                <path fillRule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clipRule="evenodd" />
            </svg>
        </div>
    );
}

export default function History({
    captures,
    isAdmin,
}: PageProps<{ captures: PaginatedCaptures; isAdmin: boolean }>) {
    return (
        <AuthenticatedLayout
            header={
                <h2 className="text-xl font-semibold leading-tight text-gray-800">
                    Capture History
                </h2>
            }
        >
            <Head title="Capture History" />

            <div className="py-12">
                <div className="mx-auto max-w-7xl sm:px-6 lg:px-8">
                    <div className="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                        <div className="overflow-x-auto">
                            <table className="min-w-full divide-y divide-gray-200">
                                <thead className="bg-gray-50">
                                    <tr>
                                        <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Media</th>
                                        <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Status</th>
                                        <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Trigger</th>
                                        <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Device</th>
                                        {isAdmin && (
                                            <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">User</th>
                                        )}
                                        <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Captured At</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-gray-200 bg-white">
                                    {captures.data.length === 0 && (
                                        <tr>
                                            <td colSpan={isAdmin ? 6 : 5} className="px-6 py-8 text-center text-sm text-gray-500">
                                                No captures yet.
                                            </td>
                                        </tr>
                                    )}
                                    {captures.data.map((capture) => (
                                        <tr key={capture.id}>
                                            <td className="px-6 py-4">
                                                <ThumbnailCell capture={capture} />
                                            </td>
                                            <td className="whitespace-nowrap px-6 py-4">
                                                <CaptureStatusBadge status={capture.status} />
                                            </td>
                                            <td className="whitespace-nowrap px-6 py-4 text-sm text-gray-500 capitalize">
                                                {capture.trigger_source.replace('_', ' ')}
                                            </td>
                                            <td className="whitespace-nowrap px-6 py-4 text-sm text-gray-500">
                                                {capture.device?.name ?? '—'}
                                            </td>
                                            {isAdmin && (
                                                <td className="whitespace-nowrap px-6 py-4 text-sm text-gray-500">
                                                    {capture.user ? (
                                                        <div>
                                                            <div>{capture.user.name}</div>
                                                            <div className="text-xs text-gray-400">{capture.user.email}</div>
                                                        </div>
                                                    ) : '—'}
                                                </td>
                                            )}
                                            <td className="whitespace-nowrap px-6 py-4 text-sm text-gray-500">
                                                {new Date(capture.captured_at).toLocaleString()}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>

                        {captures.meta.last_page > 1 && (
                            <div className="flex items-center justify-between border-t border-gray-200 px-6 py-3">
                                <p className="text-sm text-gray-700">
                                    Page {captures.meta.current_page} of {captures.meta.last_page} — {captures.meta.total} total
                                </p>
                                <div className="flex gap-1">
                                    {captures.links.map((link, i) => (
                                        link.url ? (
                                            <Link
                                                key={i}
                                                href={link.url}
                                                className={`rounded px-3 py-1 text-sm ${link.active ? 'bg-gray-800 text-white' : 'bg-white text-gray-700 hover:bg-gray-50 border border-gray-300'}`}
                                                dangerouslySetInnerHTML={{ __html: link.label }}
                                            />
                                        ) : (
                                            <span
                                                key={i}
                                                className="rounded border border-gray-200 px-3 py-1 text-sm text-gray-400"
                                                dangerouslySetInnerHTML={{ __html: link.label }}
                                            />
                                        )
                                    ))}
                                </div>
                            </div>
                        )}
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
```

---

### Task 6: History Nav Link

**Files:**
- Modify: `resources/js/Layouts/AuthenticatedLayout.tsx`

- [ ] **Step 1: Add History NavLink in desktop nav**

In `AuthenticatedLayout.tsx`, after the Dashboard `NavLink` and before the `{user.is_admin && ...}` Devices block:

```tsx
<NavLink
    href={route('captures.index')}
    active={route().current('captures.*')}
>
    History
</NavLink>
```

- [ ] **Step 2: Add History ResponsiveNavLink in mobile nav**

After the Dashboard `ResponsiveNavLink` and before the `{user.is_admin && ...}` Devices block:

```tsx
<ResponsiveNavLink
    href={route('captures.index')}
    active={route().current('captures.*')}
>
    History
</ResponsiveNavLink>
```

---

### Task 7: Final Verification

- [ ] **Step 1: Run all tests**

Run: `cd /Users/mr.casanova/Code/wolf && php artisan test`
Expected: 38 tests pass.

- [ ] **Step 2: Build frontend for TypeScript errors**

Run: `cd /Users/mr.casanova/Code/wolf && nvm use v20.19.3 && npm run build`
Expected: Build succeeds with no errors.

---
