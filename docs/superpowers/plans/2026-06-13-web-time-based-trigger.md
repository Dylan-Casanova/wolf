# Web Time-Based Geofence Trigger Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the web's broken background-tab GPS polling with a server-scheduled time-based trigger. When a web user enables the geofence, a modal shows distance + estimated arrival time, the user confirms (or adjusts up to 180 minutes), and the server schedules a delayed job that fires the servo when the timer ends. The future native app will keep the existing live-location `/check` endpoint untouched.

**Architecture:** New `scheduled_geofence_triggers` table tracks pending/fired/cancelled triggers per geofence (one active at a time). A `TriggerScheduledGeofenceJob` is dispatched with a delay; on fire, it atomically claims the trigger record (transitions `pending → fired`) and calls the existing `DeviceInterface::triggerServo`. Three new endpoints on `GeoFenceController`: `estimate` (one-shot distance/ETA calc), `scheduleTrigger` (creates trigger record + dispatches delayed job), `cancelScheduledTrigger` (marks cancelled — the job no-ops on fire). The frontend rewrites `GeofenceToggle` to handle one-shot GPS read → estimate → modal → schedule → live countdown UI. The existing live-polling `useGeolocation` hook is deleted (the native app will use OS geofencing later, not browser polling).

**Tech Stack:** Laravel 11, Sanctum, Inertia.js, React 18 + TypeScript, Tailwind, Redis queue (already running via `docker-compose.yml` `queue` service).

---

## File Structure

**Backend (create):**
- `database/migrations/2026_06_13_000000_create_scheduled_geofence_triggers_table.php` — schema
- `app/Models/ScheduledGeofenceTrigger.php` — model
- `database/factories/ScheduledGeofenceTriggerFactory.php` — factory for tests
- `app/Jobs/TriggerScheduledGeofenceJob.php` — delayed job
- `config/wolf.php` — config for `estimated_arrival_mph` (default 35)

**Backend (modify):**
- `app/Models/GeoFence.php` — add `pendingScheduledTrigger()` relationship
- `app/Http/Controllers/GeoFenceController.php` — add `estimate`, `scheduleTrigger`, `cancelScheduledTrigger` methods
- `routes/web.php` — three new routes
- `tests/Feature/GeoFenceTest.php` — add tests for the new endpoints + job

**Frontend (create):**
- `resources/js/Components/ScheduleModal.tsx` — the time-input modal

**Frontend (modify):**
- `resources/js/Components/GeofenceToggle.tsx` — rewrite to drive scheduling + countdown
- `resources/js/Pages/Dashboard.tsx` — drop `useGeolocation`, pass full geofence to toggle
- `resources/js/Pages/Geofence/Index.tsx` — drop `useGeolocation` for trigger; user-position-on-map display goes away
- `resources/js/types/index.d.ts` — extend `Geofence` type with `pending_scheduled_trigger`

**Frontend (delete):**
- `resources/js/hooks/useGeolocation.ts` — no longer used by web; native app will use OS APIs, not this hook
- `resources/js/hooks/` — directory deleted if empty

---

## Task 1: Database Schema + Model + Factory

**Files:**
- Create: `database/migrations/2026_06_13_000000_create_scheduled_geofence_triggers_table.php`
- Create: `app/Models/ScheduledGeofenceTrigger.php`
- Create: `database/factories/ScheduledGeofenceTriggerFactory.php`
- Modify: `app/Models/GeoFence.php`
- Test: `tests/Feature/GeoFenceTest.php`

- [ ] **Step 1: Create migration**

Create `database/migrations/2026_06_13_000000_create_scheduled_geofence_triggers_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scheduled_geofence_triggers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('geo_fence_id')->constrained()->cascadeOnDelete();
            $table->timestamp('scheduled_at');
            $table->string('status', 16)->default('pending');
            $table->decimal('origin_lat', 10, 7);
            $table->decimal('origin_lng', 10, 7);
            $table->decimal('origin_distance_meters', 12, 2);
            $table->timestamps();

            $table->index(['geo_fence_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduled_geofence_triggers');
    }
};
```

- [ ] **Step 2: Create model**

Create `app/Models/ScheduledGeofenceTrigger.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScheduledGeofenceTrigger extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';
    public const STATUS_FIRED = 'fired';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'geo_fence_id',
        'scheduled_at',
        'status',
        'origin_lat',
        'origin_lng',
        'origin_distance_meters',
    ];

    protected $casts = [
        'scheduled_at' => 'datetime',
        'origin_lat' => 'float',
        'origin_lng' => 'float',
        'origin_distance_meters' => 'float',
    ];

    public function geoFence(): BelongsTo
    {
        return $this->belongsTo(GeoFence::class);
    }
}
```

- [ ] **Step 3: Create factory**

Create `database/factories/ScheduledGeofenceTriggerFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\GeoFence;
use App\Models\ScheduledGeofenceTrigger;
use Illuminate\Database\Eloquent\Factories\Factory;

class ScheduledGeofenceTriggerFactory extends Factory
{
    protected $model = ScheduledGeofenceTrigger::class;

    public function definition(): array
    {
        return [
            'geo_fence_id' => GeoFence::factory(),
            'scheduled_at' => now()->addMinutes(15),
            'status' => ScheduledGeofenceTrigger::STATUS_PENDING,
            'origin_lat' => 29.4250,
            'origin_lng' => -98.4915,
            'origin_distance_meters' => 8000.0,
        ];
    }

    public function cancelled(): static
    {
        return $this->state(fn () => ['status' => ScheduledGeofenceTrigger::STATUS_CANCELLED]);
    }

    public function fired(): static
    {
        return $this->state(fn () => ['status' => ScheduledGeofenceTrigger::STATUS_FIRED]);
    }
}
```

- [ ] **Step 4: Add relationship to GeoFence**

Modify `app/Models/GeoFence.php`. After the existing `user()` method, add:

```php
public function scheduledTriggers(): \Illuminate\Database\Eloquent\Relations\HasMany
{
    return $this->hasMany(ScheduledGeofenceTrigger::class);
}

public function pendingScheduledTrigger(): \Illuminate\Database\Eloquent\Relations\HasOne
{
    return $this->hasOne(ScheduledGeofenceTrigger::class)
        ->where('status', ScheduledGeofenceTrigger::STATUS_PENDING)
        ->latestOfMany();
}
```

- [ ] **Step 5: Write failing test for relationship + persistence**

Add to `tests/Feature/GeoFenceTest.php` at the end of the class:

```php
public function test_geofence_has_pending_scheduled_trigger_relationship(): void
{
    $user = User::factory()->create();
    $fence = GeoFence::factory()->create(['user_id' => $user->id]);
    $cancelled = \App\Models\ScheduledGeofenceTrigger::factory()->cancelled()->create(['geo_fence_id' => $fence->id]);
    $pending = \App\Models\ScheduledGeofenceTrigger::factory()->create(['geo_fence_id' => $fence->id]);

    $fresh = $fence->fresh()->load('pendingScheduledTrigger');

    $this->assertNotNull($fresh->pendingScheduledTrigger);
    $this->assertEquals($pending->id, $fresh->pendingScheduledTrigger->id);
}
```

- [ ] **Step 6: Run migration + test**

Run:
```bash
docker compose exec app php artisan migrate
docker compose exec app php artisan test --filter test_geofence_has_pending_scheduled_trigger_relationship
```

Expected: PASS

- [ ] **Step 7: Commit**

```bash
git add database/migrations/2026_06_13_000000_create_scheduled_geofence_triggers_table.php \
        app/Models/ScheduledGeofenceTrigger.php \
        database/factories/ScheduledGeofenceTriggerFactory.php \
        app/Models/GeoFence.php \
        tests/Feature/GeoFenceTest.php
git commit -m "feat: add scheduled_geofence_triggers table and model"
```

---

## Task 2: Config for estimated arrival speed

**Files:**
- Create: `config/wolf.php`

- [ ] **Step 1: Create config file**

Create `config/wolf.php`:

```php
<?php

return [
    /*
    | Default speed (mph) used to estimate arrival time for the web's
    | time-based geofence trigger. Single point estimate; not routing-aware.
    */
    'estimated_arrival_mph' => env('WOLF_ESTIMATED_ARRIVAL_MPH', 35),
];
```

- [ ] **Step 2: Commit**

```bash
git add config/wolf.php
git commit -m "feat: add wolf config with estimated arrival mph"
```

---

## Task 3: TriggerScheduledGeofenceJob

**Files:**
- Create: `app/Jobs/TriggerScheduledGeofenceJob.php`
- Modify: `tests/Feature/GeoFenceTest.php`

- [ ] **Step 1: Create the job**

Create `app/Jobs/TriggerScheduledGeofenceJob.php`:

```php
<?php

namespace App\Jobs;

use App\Contracts\DeviceInterface;
use App\Models\ScheduledGeofenceTrigger;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class TriggerScheduledGeofenceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $scheduledTriggerId) {}

    public function handle(DeviceInterface $device): void
    {
        // Atomic claim: only fire if still pending
        $claimed = ScheduledGeofenceTrigger::where('id', $this->scheduledTriggerId)
            ->where('status', ScheduledGeofenceTrigger::STATUS_PENDING)
            ->update(['status' => ScheduledGeofenceTrigger::STATUS_FIRED]);

        if (! $claimed) {
            return; // cancelled, already fired, or deleted
        }

        $trigger = ScheduledGeofenceTrigger::with('geoFence.user.devices')->find($this->scheduledTriggerId);
        if (! $trigger || ! $trigger->geoFence) {
            return;
        }

        $fence = $trigger->geoFence;
        $esp = $fence->user->devices()->where('type', 'esp8266')->first();
        if ($esp) {
            $device->triggerServo($esp);
        }

        $fence->update(['is_active' => false]);
    }
}
```

- [ ] **Step 2: Write failing test — job fires when pending**

Add to `tests/Feature/GeoFenceTest.php`:

```php
public function test_trigger_job_fires_servo_when_pending(): void
{
    $user = User::factory()->create();
    Device::factory()->esp8266()->online()->create(['user_id' => $user->id]);
    $fence = GeoFence::factory()->active()->create(['user_id' => $user->id]);
    $trigger = \App\Models\ScheduledGeofenceTrigger::factory()->create(['geo_fence_id' => $fence->id]);

    $mock = Mockery::mock(DeviceInterface::class);
    $mock->shouldReceive('triggerServo')->once()->andReturn(true);
    $this->app->instance(DeviceInterface::class, $mock);

    (new \App\Jobs\TriggerScheduledGeofenceJob($trigger->id))->handle($mock);

    $this->assertEquals('fired', $trigger->fresh()->status);
    $this->assertFalse($fence->fresh()->is_active);
}
```

- [ ] **Step 3: Run test**

```bash
docker compose exec app php artisan test --filter test_trigger_job_fires_servo_when_pending
```

Expected: PASS

- [ ] **Step 4: Write failing test — job no-ops when cancelled**

Add to `tests/Feature/GeoFenceTest.php`:

```php
public function test_trigger_job_does_not_fire_when_cancelled(): void
{
    $user = User::factory()->create();
    Device::factory()->esp8266()->online()->create(['user_id' => $user->id]);
    $fence = GeoFence::factory()->active()->create(['user_id' => $user->id]);
    $trigger = \App\Models\ScheduledGeofenceTrigger::factory()->cancelled()->create(['geo_fence_id' => $fence->id]);

    $mock = Mockery::mock(DeviceInterface::class);
    $mock->shouldReceive('triggerServo')->never();
    $this->app->instance(DeviceInterface::class, $mock);

    (new \App\Jobs\TriggerScheduledGeofenceJob($trigger->id))->handle($mock);

    $this->assertEquals('cancelled', $trigger->fresh()->status);
    $this->assertTrue($fence->fresh()->is_active);
}
```

- [ ] **Step 5: Run test**

```bash
docker compose exec app php artisan test --filter test_trigger_job_does_not_fire_when_cancelled
```

Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add app/Jobs/TriggerScheduledGeofenceJob.php tests/Feature/GeoFenceTest.php
git commit -m "feat: add TriggerScheduledGeofenceJob with atomic claim"
```

---

## Task 4: Estimate endpoint

**Files:**
- Modify: `app/Http/Controllers/GeoFenceController.php`
- Modify: `routes/web.php`
- Modify: `tests/Feature/GeoFenceTest.php`

- [ ] **Step 1: Add route**

Modify `routes/web.php`. In the geofence section (currently around the `check` and `toggle` routes), add:

```php
Route::post('geo-fences/{geoFence}/estimate', [GeoFenceController::class, 'estimate']);
```

Place it next to the existing `check` and `toggle` routes for `geo-fences`.

- [ ] **Step 2: Write failing test**

Add to `tests/Feature/GeoFenceTest.php`:

```php
public function test_estimate_returns_distance_and_minutes(): void
{
    $user = User::factory()->create();
    $fence = GeoFence::factory()->create([
        'user_id' => $user->id,
        'north_lat' => 29.4260,
        'south_lat' => 29.4240,
        'east_lng' => -98.4900,
        'west_lng' => -98.4930,
    ]);

    $response = $this->actingAs($user)->postJson("/geo-fences/{$fence->id}/estimate", [
        'lat' => 29.5,
        'lng' => -98.5,
    ]);

    $response->assertOk();
    $response->assertJsonStructure(['distance_miles', 'estimated_minutes', 'assumed_speed_mph']);
    $this->assertGreaterThan(0, $response->json('distance_miles'));
    $this->assertGreaterThanOrEqual(1, $response->json('estimated_minutes'));
    $this->assertEquals(35, $response->json('assumed_speed_mph'));
}
```

- [ ] **Step 3: Run test to see it fail**

```bash
docker compose exec app php artisan test --filter test_estimate_returns_distance_and_minutes
```

Expected: FAIL (route or method missing).

- [ ] **Step 4: Implement estimate method**

Modify `app/Http/Controllers/GeoFenceController.php`. Add this method after the existing `check` method:

```php
public function estimate(Request $request, GeoFence $geoFence): JsonResponse
{
    if ($geoFence->user_id !== $request->user()->id) {
        return response()->json(['message' => 'Forbidden.'], 403);
    }

    $validated = $request->validate([
        'lat' => ['required', 'numeric', 'between:-90,90'],
        'lng' => ['required', 'numeric', 'between:-180,180'],
    ]);

    $distanceMeters = $geoFence->distanceFromCenter($validated['lat'], $validated['lng']);
    $distanceMiles = $distanceMeters / 1609.34;
    $speedMph = config('wolf.estimated_arrival_mph', 35);
    $estimatedMinutes = (int) max(1, round(($distanceMiles / $speedMph) * 60));

    return response()->json([
        'distance_miles' => round($distanceMiles, 1),
        'estimated_minutes' => $estimatedMinutes,
        'assumed_speed_mph' => $speedMph,
    ]);
}
```

- [ ] **Step 5: Run test**

```bash
docker compose exec app php artisan test --filter test_estimate_returns_distance_and_minutes
```

Expected: PASS

- [ ] **Step 6: Write failing test for ownership check**

Add to `tests/Feature/GeoFenceTest.php`:

```php
public function test_estimate_rejects_other_users_geofence(): void
{
    $user = User::factory()->create();
    $other = User::factory()->create();
    $fence = GeoFence::factory()->create(['user_id' => $other->id]);

    $response = $this->actingAs($user)->postJson("/geo-fences/{$fence->id}/estimate", [
        'lat' => 29.5,
        'lng' => -98.5,
    ]);

    $response->assertStatus(403);
}
```

- [ ] **Step 7: Run test**

```bash
docker compose exec app php artisan test --filter test_estimate_rejects_other_users_geofence
```

Expected: PASS (already covered by the method's auth check)

- [ ] **Step 8: Commit**

```bash
git add app/Http/Controllers/GeoFenceController.php routes/web.php tests/Feature/GeoFenceTest.php
git commit -m "feat: add geofence estimate endpoint"
```

---

## Task 5: Schedule trigger endpoint

**Files:**
- Modify: `app/Http/Controllers/GeoFenceController.php`
- Modify: `routes/web.php`
- Modify: `tests/Feature/GeoFenceTest.php`

- [ ] **Step 1: Add route**

Modify `routes/web.php`. Add next to the estimate route:

```php
Route::post('geo-fences/{geoFence}/schedule-trigger', [GeoFenceController::class, 'scheduleTrigger']);
```

- [ ] **Step 2: Write failing test — schedule creates trigger and activates fence**

Add to `tests/Feature/GeoFenceTest.php`:

```php
public function test_schedule_trigger_creates_pending_record_and_activates_fence(): void
{
    \Illuminate\Support\Facades\Queue::fake();

    $user = User::factory()->create();
    $fence = GeoFence::factory()->create(['user_id' => $user->id, 'is_active' => false]);

    $response = $this->actingAs($user)->postJson("/geo-fences/{$fence->id}/schedule-trigger", [
        'minutes' => 15,
        'origin_lat' => 29.5,
        'origin_lng' => -98.5,
    ]);

    $response->assertOk();
    $response->assertJsonStructure(['scheduled_trigger_id', 'scheduled_at', 'fence' => ['is_active']]);
    $this->assertTrue($response->json('fence.is_active'));
    $this->assertTrue($fence->fresh()->is_active);
    $this->assertDatabaseHas('scheduled_geofence_triggers', [
        'geo_fence_id' => $fence->id,
        'status' => 'pending',
    ]);

    \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\TriggerScheduledGeofenceJob::class);
}
```

- [ ] **Step 3: Run test to confirm it fails**

```bash
docker compose exec app php artisan test --filter test_schedule_trigger_creates_pending_record_and_activates_fence
```

Expected: FAIL.

- [ ] **Step 4: Implement scheduleTrigger method**

Modify `app/Http/Controllers/GeoFenceController.php`. Add at the top of the file:

```php
use App\Jobs\TriggerScheduledGeofenceJob;
use App\Models\ScheduledGeofenceTrigger;
```

Add this method after `estimate`:

```php
public function scheduleTrigger(Request $request, GeoFence $geoFence): JsonResponse
{
    if ($geoFence->user_id !== $request->user()->id) {
        return response()->json(['message' => 'Forbidden.'], 403);
    }

    $validated = $request->validate([
        'minutes' => ['required', 'integer', 'between:1,180'],
        'origin_lat' => ['required', 'numeric', 'between:-90,90'],
        'origin_lng' => ['required', 'numeric', 'between:-180,180'],
    ]);

    // Cancel any existing pending trigger for this fence (one active at a time)
    ScheduledGeofenceTrigger::where('geo_fence_id', $geoFence->id)
        ->where('status', ScheduledGeofenceTrigger::STATUS_PENDING)
        ->update(['status' => ScheduledGeofenceTrigger::STATUS_CANCELLED]);

    $distanceMeters = $geoFence->distanceFromCenter($validated['origin_lat'], $validated['origin_lng']);
    $scheduledAt = now()->addMinutes($validated['minutes']);

    $trigger = ScheduledGeofenceTrigger::create([
        'geo_fence_id' => $geoFence->id,
        'scheduled_at' => $scheduledAt,
        'origin_lat' => $validated['origin_lat'],
        'origin_lng' => $validated['origin_lng'],
        'origin_distance_meters' => $distanceMeters,
        'status' => ScheduledGeofenceTrigger::STATUS_PENDING,
    ]);

    $geoFence->update(['is_active' => true]);

    TriggerScheduledGeofenceJob::dispatch($trigger->id)->delay($scheduledAt);

    return response()->json([
        'scheduled_trigger_id' => $trigger->id,
        'scheduled_at' => $scheduledAt->toIso8601String(),
        'fence' => ['is_active' => true],
    ]);
}
```

- [ ] **Step 5: Run test**

```bash
docker compose exec app php artisan test --filter test_schedule_trigger_creates_pending_record_and_activates_fence
```

Expected: PASS.

- [ ] **Step 6: Write failing test — validation rejects out-of-range minutes**

Add to `tests/Feature/GeoFenceTest.php`:

```php
public function test_schedule_trigger_rejects_minutes_over_180(): void
{
    $user = User::factory()->create();
    $fence = GeoFence::factory()->create(['user_id' => $user->id]);

    $response = $this->actingAs($user)->postJson("/geo-fences/{$fence->id}/schedule-trigger", [
        'minutes' => 181,
        'origin_lat' => 29.5,
        'origin_lng' => -98.5,
    ]);

    $response->assertStatus(422);
}

public function test_schedule_trigger_rejects_zero_minutes(): void
{
    $user = User::factory()->create();
    $fence = GeoFence::factory()->create(['user_id' => $user->id]);

    $response = $this->actingAs($user)->postJson("/geo-fences/{$fence->id}/schedule-trigger", [
        'minutes' => 0,
        'origin_lat' => 29.5,
        'origin_lng' => -98.5,
    ]);

    $response->assertStatus(422);
}
```

- [ ] **Step 7: Run tests**

```bash
docker compose exec app php artisan test --filter test_schedule_trigger_rejects
```

Expected: PASS.

- [ ] **Step 8: Write failing test — scheduling cancels prior pending trigger**

Add to `tests/Feature/GeoFenceTest.php`:

```php
public function test_scheduling_cancels_prior_pending_trigger(): void
{
    \Illuminate\Support\Facades\Queue::fake();

    $user = User::factory()->create();
    $fence = GeoFence::factory()->create(['user_id' => $user->id]);
    $prior = \App\Models\ScheduledGeofenceTrigger::factory()->create(['geo_fence_id' => $fence->id]);

    $response = $this->actingAs($user)->postJson("/geo-fences/{$fence->id}/schedule-trigger", [
        'minutes' => 30,
        'origin_lat' => 29.5,
        'origin_lng' => -98.5,
    ]);

    $response->assertOk();
    $this->assertEquals('cancelled', $prior->fresh()->status);
}
```

- [ ] **Step 9: Run test**

```bash
docker compose exec app php artisan test --filter test_scheduling_cancels_prior_pending_trigger
```

Expected: PASS.

- [ ] **Step 10: Commit**

```bash
git add app/Http/Controllers/GeoFenceController.php routes/web.php tests/Feature/GeoFenceTest.php
git commit -m "feat: add geofence schedule-trigger endpoint with delayed job dispatch"
```

---

## Task 6: Cancel scheduled trigger endpoint

**Files:**
- Modify: `app/Http/Controllers/GeoFenceController.php`
- Modify: `routes/web.php`
- Modify: `tests/Feature/GeoFenceTest.php`

- [ ] **Step 1: Add route**

Modify `routes/web.php`. Add:

```php
Route::delete('geo-fences/{geoFence}/scheduled-trigger', [GeoFenceController::class, 'cancelScheduledTrigger']);
```

- [ ] **Step 2: Write failing test**

Add to `tests/Feature/GeoFenceTest.php`:

```php
public function test_cancel_scheduled_trigger_marks_cancelled_and_deactivates_fence(): void
{
    $user = User::factory()->create();
    $fence = GeoFence::factory()->active()->create(['user_id' => $user->id]);
    $trigger = \App\Models\ScheduledGeofenceTrigger::factory()->create(['geo_fence_id' => $fence->id]);

    $response = $this->actingAs($user)->deleteJson("/geo-fences/{$fence->id}/scheduled-trigger");

    $response->assertOk();
    $response->assertJson(['fence' => ['is_active' => false]]);
    $this->assertEquals('cancelled', $trigger->fresh()->status);
    $this->assertFalse($fence->fresh()->is_active);
}
```

- [ ] **Step 3: Run test to confirm it fails**

```bash
docker compose exec app php artisan test --filter test_cancel_scheduled_trigger_marks_cancelled_and_deactivates_fence
```

Expected: FAIL.

- [ ] **Step 4: Implement cancelScheduledTrigger method**

Modify `app/Http/Controllers/GeoFenceController.php`. Add this method after `scheduleTrigger`:

```php
public function cancelScheduledTrigger(Request $request, GeoFence $geoFence): JsonResponse
{
    if ($geoFence->user_id !== $request->user()->id) {
        return response()->json(['message' => 'Forbidden.'], 403);
    }

    ScheduledGeofenceTrigger::where('geo_fence_id', $geoFence->id)
        ->where('status', ScheduledGeofenceTrigger::STATUS_PENDING)
        ->update(['status' => ScheduledGeofenceTrigger::STATUS_CANCELLED]);

    $geoFence->update(['is_active' => false]);

    return response()->json(['fence' => ['is_active' => false]]);
}
```

- [ ] **Step 5: Run test**

```bash
docker compose exec app php artisan test --filter test_cancel_scheduled_trigger_marks_cancelled_and_deactivates_fence
```

Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/GeoFenceController.php routes/web.php tests/Feature/GeoFenceTest.php
git commit -m "feat: add geofence cancel-scheduled-trigger endpoint"
```

---

## Task 7: Eager-load pending trigger on Inertia routes

**Files:**
- Modify: `routes/web.php`

- [ ] **Step 1: Update Dashboard route to eager-load pending trigger**

Modify `routes/web.php`. The dashboard route currently does:

```php
return Inertia::render('Dashboard', [
    'devices' => $devices,
    'geofence' => $user->geofence,
]);
```

Change the `geofence` line to:

```php
'geofence' => $user->geofence?->load('pendingScheduledTrigger'),
```

- [ ] **Step 2: Update Geofence route**

In the same file, the `/geofence` route currently does:

```php
$geofence = auth()->user()->geofence;
return Inertia::render('Geofence/Index', [
    'geofence' => $geofence,
]);
```

Change it to:

```php
$geofence = auth()->user()->geofence?->load('pendingScheduledTrigger');
return Inertia::render('Geofence/Index', [
    'geofence' => $geofence,
]);
```

- [ ] **Step 3: Verify by hitting dashboard**

Run a quick sanity check:

```bash
docker compose exec app php artisan tinker --execute='dump(\App\Models\User::first()?->geofence?->load("pendingScheduledTrigger")?->toArray());'
```

Expected: Output includes a `pending_scheduled_trigger` key (null if no active trigger).

- [ ] **Step 4: Commit**

```bash
git add routes/web.php
git commit -m "feat: eager-load pending scheduled trigger on geofence Inertia props"
```

---

## Task 8: Update TypeScript Geofence type

**Files:**
- Modify: `resources/js/types/index.d.ts`

- [ ] **Step 1: Extend the Geofence interface**

Modify `resources/js/types/index.d.ts`. Find the `Geofence` interface and replace it with:

```ts
export interface ScheduledTrigger {
    id: number;
    scheduled_at: string;
    status: 'pending' | 'fired' | 'cancelled';
    origin_lat: number;
    origin_lng: number;
    origin_distance_meters: number;
}

export interface Geofence {
    id: number;
    user_id: number;
    north_lat: number;
    south_lat: number;
    east_lng: number;
    west_lng: number;
    is_active: boolean;
    pending_scheduled_trigger: ScheduledTrigger | null;
}
```

- [ ] **Step 2: Typecheck**

```bash
npx tsc --noEmit 2>&1 | head -30
```

Expected: no errors. If there are errors, they're in callers that need updating in later tasks — note them but continue.

- [ ] **Step 3: Commit**

```bash
git add resources/js/types/index.d.ts
git commit -m "feat: add pending_scheduled_trigger to Geofence type"
```

---

## Task 9: ScheduleModal component

**Files:**
- Create: `resources/js/Components/ScheduleModal.tsx`

- [ ] **Step 1: Create ScheduleModal**

Create `resources/js/Components/ScheduleModal.tsx`:

```tsx
import { useState } from 'react';

interface ScheduleModalProps {
    distanceMiles: number;
    estimatedMinutes: number;
    onConfirm: (minutes: number) => void;
    onClose: () => void;
}

export default function ScheduleModal({
    distanceMiles,
    estimatedMinutes,
    onConfirm,
    onClose,
}: ScheduleModalProps) {
    const [minutes, setMinutes] = useState(estimatedMinutes);

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
            <div className="w-full max-w-md rounded-lg bg-white p-6 shadow-xl">
                <h3 className="text-lg font-semibold text-gray-900">
                    Web mode: time-based trigger
                </h3>
                <p className="mt-2 text-sm text-gray-600">
                    The app uses your live location. The web uses a timer.
                </p>

                <div className="mt-4 rounded-md bg-gray-50 p-3 text-sm">
                    <div className="flex items-center justify-between">
                        <span className="text-gray-600">Estimated arrival</span>
                        <span className="font-semibold text-gray-900">
                            {estimatedMinutes} min
                        </span>
                    </div>
                    <div className="mt-1 flex items-center justify-between">
                        <span className="text-gray-600">Distance</span>
                        <span className="text-gray-900">
                            {distanceMiles.toFixed(1)} mi
                        </span>
                    </div>
                </div>

                <label className="mt-4 block">
                    <span className="text-sm font-medium text-gray-700">
                        Open garage in (minutes)
                    </span>
                    <input
                        type="number"
                        min={1}
                        max={180}
                        value={minutes}
                        onChange={(e) => {
                            const v = parseInt(e.target.value, 10);
                            if (!isNaN(v)) {
                                setMinutes(Math.max(1, Math.min(180, v)));
                            }
                        }}
                        className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                    />
                    <span className="mt-1 block text-xs text-gray-500">
                        Max 180 minutes (3 hours).
                    </span>
                </label>

                <p className="mt-4 text-xs text-gray-500">
                    Garage opens when the timer ends. Cancel anytime.
                </p>

                <div className="mt-6 flex justify-end gap-3">
                    <button
                        onClick={onClose}
                        className="rounded-md px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-100"
                    >
                        Cancel
                    </button>
                    <button
                        onClick={() => onConfirm(minutes)}
                        className="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700"
                    >
                        Start Timer
                    </button>
                </div>
            </div>
        </div>
    );
}
```

- [ ] **Step 2: Typecheck**

```bash
npx tsc --noEmit 2>&1 | head -30
```

Expected: no errors.

- [ ] **Step 3: Commit**

```bash
git add resources/js/Components/ScheduleModal.tsx
git commit -m "feat: add ScheduleModal component"
```

---

## Task 10: Rewrite GeofenceToggle

**Files:**
- Modify: `resources/js/Components/GeofenceToggle.tsx` (full rewrite)

- [ ] **Step 1: Replace GeofenceToggle entirely**

Replace the contents of `resources/js/Components/GeofenceToggle.tsx` with:

```tsx
import ScheduleModal from '@/Components/ScheduleModal';
import { Geofence } from '@/types';
import { router } from '@inertiajs/react';
import axios from 'axios';
import { useEffect, useState } from 'react';

interface GeofenceToggleProps {
    geofence: Geofence;
}

export default function GeofenceToggle({ geofence }: GeofenceToggleProps) {
    const [loading, setLoading] = useState(false);
    const [modalOpen, setModalOpen] = useState(false);
    const [estimate, setEstimate] = useState<{
        distance_miles: number;
        estimated_minutes: number;
    } | null>(null);
    const [origin, setOrigin] = useState<{ lat: number; lng: number } | null>(
        null,
    );
    const [error, setError] = useState<string | null>(null);
    const [countdownText, setCountdownText] = useState<string>('');

    useEffect(() => {
        const pending = geofence.pending_scheduled_trigger;
        if (!pending) {
            setCountdownText('');
            return;
        }
        const fireAt = new Date(pending.scheduled_at).getTime();
        const tick = () => {
            const ms = fireAt - Date.now();
            if (ms <= 0) {
                setCountdownText('Triggering...');
                return;
            }
            const mins = Math.floor(ms / 60000);
            const secs = Math.floor((ms % 60000) / 1000);
            setCountdownText(`${mins}:${secs.toString().padStart(2, '0')}`);
        };
        tick();
        const id = setInterval(tick, 1000);
        return () => clearInterval(id);
    }, [geofence.pending_scheduled_trigger]);

    const getCurrentPosition = (): Promise<GeolocationPosition> => {
        return new Promise((resolve, reject) => {
            navigator.geolocation.getCurrentPosition(resolve, reject, {
                enableHighAccuracy: true,
                timeout: 10000,
            });
        });
    };

    const handleEnable = async () => {
        setError(null);
        if (!navigator.geolocation) {
            setError('Your browser does not support location services.');
            return;
        }
        setLoading(true);
        try {
            const pos = await getCurrentPosition();
            const lat = pos.coords.latitude;
            const lng = pos.coords.longitude;
            setOrigin({ lat, lng });
            const response = await axios.post(
                `/geo-fences/${geofence.id}/estimate`,
                { lat, lng },
            );
            setEstimate(response.data);
            setModalOpen(true);
        } catch {
            setError(
                'Failed to read your location. Please allow access and try again.',
            );
        } finally {
            setLoading(false);
        }
    };

    const handleConfirm = async (minutes: number) => {
        if (!origin) return;
        setLoading(true);
        try {
            await axios.post(`/geo-fences/${geofence.id}/schedule-trigger`, {
                minutes,
                origin_lat: origin.lat,
                origin_lng: origin.lng,
            });
            setModalOpen(false);
            router.reload();
        } catch {
            setError('Failed to schedule trigger.');
        } finally {
            setLoading(false);
        }
    };

    const handleCancelScheduled = async () => {
        setLoading(true);
        try {
            await axios.delete(`/geo-fences/${geofence.id}/scheduled-trigger`);
            router.reload();
        } catch {
            setError('Failed to cancel.');
        } finally {
            setLoading(false);
        }
    };

    const isArmed =
        geofence.is_active && !!geofence.pending_scheduled_trigger;

    return (
        <div className="flex flex-col gap-2">
            <button
                onClick={isArmed ? handleCancelScheduled : handleEnable}
                disabled={loading}
                className={`inline-flex items-center gap-2 rounded-lg px-4 py-2 text-sm font-semibold transition-colors ${
                    isArmed
                        ? 'bg-green-600 text-white hover:bg-green-700'
                        : 'bg-gray-200 text-gray-700 hover:bg-gray-300'
                } disabled:opacity-50`}
            >
                <span
                    className={`h-2.5 w-2.5 rounded-full ${
                        isArmed ? 'animate-pulse bg-white' : 'bg-gray-400'
                    }`}
                />
                {loading
                    ? 'Working...'
                    : isArmed
                      ? `Opens in ${countdownText} (tap to cancel)`
                      : 'Enable Geofence Tracking'}
            </button>
            {error && <p className="text-xs text-red-500">{error}</p>}
            {modalOpen && estimate && (
                <ScheduleModal
                    distanceMiles={estimate.distance_miles}
                    estimatedMinutes={estimate.estimated_minutes}
                    onConfirm={handleConfirm}
                    onClose={() => setModalOpen(false)}
                />
            )}
        </div>
    );
}
```

- [ ] **Step 2: Typecheck**

```bash
npx tsc --noEmit 2>&1 | head -30
```

Expected: TypeScript errors in `Dashboard.tsx` and `Geofence/Index.tsx` because they still pass `initialActive`/`onToggle`. That's expected — fixed in next tasks. Other errors should be zero.

- [ ] **Step 3: Commit**

```bash
git add resources/js/Components/GeofenceToggle.tsx
git commit -m "feat: rewrite GeofenceToggle to drive scheduling + countdown"
```

---

## Task 11: Update Dashboard.tsx

**Files:**
- Modify: `resources/js/Pages/Dashboard.tsx`

- [ ] **Step 1: Replace Dashboard.tsx**

Replace the contents of `resources/js/Pages/Dashboard.tsx` with:

```tsx
import GarageButton from '@/Components/GarageButton';
import GeofenceToggle from '@/Components/GeofenceToggle';
import StreamView, { StreamViewHandle } from '@/Components/StreamView';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Geofence } from '@/types';
import { Head, Link } from '@inertiajs/react';
import { useRef } from 'react';

interface DeviceInfo {
    id: number;
    name: string;
    device_id: string;
    type: 'esp32_cam' | 'esp8266';
    is_online: boolean;
}

interface DashboardProps {
    devices: DeviceInfo[];
    geofence: Geofence | null;
}

export default function Dashboard({ devices, geofence }: DashboardProps) {
    const streamRef = useRef<StreamViewHandle>(null);
    const wasStreamingRef = useRef(false);

    const sorted = [...devices].sort((a, b) => {
        if (a.type === 'esp32_cam' && b.type !== 'esp32_cam') return -1;
        if (a.type !== 'esp32_cam' && b.type === 'esp32_cam') return 1;
        return 0;
    });

    const esp32 = sorted.find((d) => d.type === 'esp32_cam');

    const handleTriggerStart = () => {
        if (esp32 && streamRef.current?.isStreaming()) {
            wasStreamingRef.current = true;
            streamRef.current.stopStream();
        }
    };

    const handleTriggerComplete = () => {
        if (esp32 && wasStreamingRef.current && streamRef.current) {
            wasStreamingRef.current = false;
            streamRef.current.startStream();
        }
    };

    return (
        <AuthenticatedLayout>
            <Head title="Dashboard" />
            <div className="py-12">
                <div className="mx-auto max-w-7xl sm:px-6 lg:px-8">
                    {devices.length === 0 ? (
                        <div className="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                            <div className="p-6 text-center text-gray-500">
                                <p className="mb-4">
                                    No devices linked to your account.
                                </p>
                                <Link
                                    href="/devices/claim"
                                    className="inline-flex items-center rounded-md bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700"
                                >
                                    Claim a Device
                                </Link>
                            </div>
                        </div>
                    ) : (
                        <div className="flex flex-col gap-6">
                            {sorted.map((device) => (
                                <div
                                    key={device.id}
                                    className="overflow-hidden bg-white shadow-sm sm:rounded-lg"
                                >
                                    <div className="border-b border-gray-100 px-6 py-3">
                                        <div className="flex items-center justify-between">
                                            <h3 className="text-sm font-semibold text-gray-700">
                                                {device.name}
                                                <span className="ml-2 text-xs font-normal text-gray-400">
                                                    {device.device_id}
                                                </span>
                                            </h3>
                                            <span
                                                className={`inline-flex items-center gap-1.5 text-xs font-medium ${
                                                    device.is_online
                                                        ? 'text-green-600'
                                                        : 'text-gray-400'
                                                }`}
                                            >
                                                <span
                                                    className={`h-2 w-2 rounded-full ${
                                                        device.is_online
                                                            ? 'bg-green-500'
                                                            : 'bg-gray-300'
                                                    }`}
                                                />
                                                {device.is_online
                                                    ? 'Online'
                                                    : 'Offline'}
                                            </span>
                                        </div>
                                    </div>
                                    <div className="flex flex-col items-center gap-4 p-6 text-gray-900">
                                        {device.type === 'esp32_cam' && (
                                            <StreamView ref={streamRef} />
                                        )}
                                        <GarageButton
                                            deviceId={device.id}
                                            onTriggerStart={handleTriggerStart}
                                            onTriggerComplete={
                                                handleTriggerComplete
                                            }
                                        />
                                    </div>
                                </div>
                            ))}
                            {geofence && (
                                <div className="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                                    <div className="border-b border-gray-100 px-6 py-3">
                                        <h3 className="text-sm font-semibold text-gray-700">
                                            Geofence
                                        </h3>
                                    </div>
                                    <div className="p-6">
                                        <GeofenceToggle geofence={geofence} />
                                    </div>
                                </div>
                            )}
                        </div>
                    )}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
```

- [ ] **Step 2: Typecheck**

```bash
npx tsc --noEmit 2>&1 | head -30
```

Expected: remaining errors only in `Geofence/Index.tsx` (next task).

- [ ] **Step 3: Commit**

```bash
git add resources/js/Pages/Dashboard.tsx
git commit -m "feat: simplify Dashboard to use new GeofenceToggle"
```

---

## Task 12: Update Geofence/Index.tsx

**Files:**
- Modify: `resources/js/Pages/Geofence/Index.tsx`

- [ ] **Step 1: Replace Geofence/Index.tsx**

Replace the contents of `resources/js/Pages/Geofence/Index.tsx` with:

```tsx
import AddressSearch from '@/Components/AddressSearch';
import GeofenceMap from '@/Components/GeofenceMap';
import GeofenceToggle from '@/Components/GeofenceToggle';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Geofence } from '@/types';
import { Head, router } from '@inertiajs/react';
import axios from 'axios';
import { useState } from 'react';

interface GeofencePageProps {
    geofence: Geofence | null;
}

export default function Index({ geofence }: GeofencePageProps) {
    const [center, setCenter] = useState<[number, number] | null>(null);
    const [bounds, setBounds] = useState<{
        north_lat: number;
        south_lat: number;
        east_lng: number;
        west_lng: number;
    } | null>(
        geofence
            ? {
                  north_lat: geofence.north_lat,
                  south_lat: geofence.south_lat,
                  east_lng: geofence.east_lng,
                  west_lng: geofence.west_lng,
              }
            : null,
    );
    const [saving, setSaving] = useState(false);
    const [showMap, setShowMap] = useState(!!geofence);

    const handleAddressSelect = (lat: number, lng: number) => {
        setCenter([lat, lng]);
        setShowMap(true);
    };

    const handleSave = async () => {
        if (!bounds) return;
        setSaving(true);
        try {
            if (geofence) {
                await axios.put(`/geo-fences/${geofence.id}`, bounds);
            } else {
                await axios.post('/geo-fences', bounds);
            }
            router.reload();
        } catch {
            // validation error
        } finally {
            setSaving(false);
        }
    };

    const handleDelete = async () => {
        if (!geofence) return;
        await axios.delete(`/geo-fences/${geofence.id}`);
        router.reload();
    };

    return (
        <AuthenticatedLayout
            header={
                <h2 className="text-xl font-semibold leading-tight text-gray-800">
                    Geofence
                </h2>
            }
        >
            <Head title="Geofence" />

            <div className="py-12">
                <div className="mx-auto max-w-7xl sm:px-6 lg:px-8">
                    <div className="overflow-visible bg-white shadow-sm sm:rounded-lg">
                        <div className="p-6">
                            {!showMap && !geofence ? (
                                <div className="flex flex-col items-center gap-6">
                                    <p className="text-gray-500">
                                        No geofence configured. Search for an
                                        address to create your perimeter.
                                    </p>
                                    <div className="w-full max-w-md">
                                        <AddressSearch
                                            onSelect={handleAddressSelect}
                                        />
                                    </div>
                                </div>
                            ) : (
                                <div className="flex flex-col gap-4">
                                    {!geofence && (
                                        <AddressSearch
                                            onSelect={handleAddressSelect}
                                        />
                                    )}

                                    <GeofenceMap
                                        geofence={geofence}
                                        center={center}
                                        userPosition={null}
                                        onBoundsChange={setBounds}
                                    />

                                    <div className="flex items-center justify-between">
                                        <div className="flex items-center gap-3">
                                            {geofence && (
                                                <GeofenceToggle
                                                    geofence={geofence}
                                                />
                                            )}
                                        </div>

                                        <div className="flex gap-2">
                                            {geofence && (
                                                <button
                                                    onClick={handleDelete}
                                                    className="rounded-md bg-red-600 px-4 py-2 text-sm font-semibold text-white hover:bg-red-700"
                                                >
                                                    Delete
                                                </button>
                                            )}
                                            <button
                                                onClick={handleSave}
                                                disabled={saving || !bounds}
                                                className="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700 disabled:opacity-50"
                                            >
                                                {saving
                                                    ? 'Saving...'
                                                    : geofence
                                                      ? 'Update Perimeter'
                                                      : 'Create Perimeter'}
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            )}
                        </div>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
```

- [ ] **Step 2: Typecheck**

```bash
npx tsc --noEmit 2>&1 | head -30
```

Expected: no errors.

- [ ] **Step 3: Commit**

```bash
git add resources/js/Pages/Geofence/Index.tsx
git commit -m "feat: simplify Geofence/Index to use new GeofenceToggle"
```

---

## Task 13: Delete useGeolocation hook

**Files:**
- Delete: `resources/js/hooks/useGeolocation.ts`
- Delete: `resources/js/hooks/` (if empty after)

- [ ] **Step 1: Confirm nothing references useGeolocation**

```bash
grep -rn "useGeolocation" /Users/mr.casanova/Code/wolf/resources/js /Users/mr.casanova/Code/wolf/app 2>/dev/null
```

Expected: no output (no references remain).

- [ ] **Step 2: Delete the hook file**

```bash
rm /Users/mr.casanova/Code/wolf/resources/js/hooks/useGeolocation.ts
```

- [ ] **Step 3: Remove the hooks directory if empty**

```bash
rmdir /Users/mr.casanova/Code/wolf/resources/js/hooks/ 2>/dev/null || echo "Directory not empty, keeping it"
```

- [ ] **Step 4: Typecheck**

```bash
npx tsc --noEmit 2>&1 | head -30
```

Expected: no errors.

- [ ] **Step 5: Commit**

```bash
git add -A resources/js/hooks/
git commit -m "chore: remove useGeolocation hook (web no longer polls)"
```

---

## Task 14: Final verification

**Files:** none

- [ ] **Step 1: Run full backend test suite**

```bash
docker compose exec app php artisan test --filter GeoFenceTest
```

Expected: all tests pass.

- [ ] **Step 2: Run full typecheck**

```bash
npx tsc --noEmit
```

Expected: zero errors, zero output.

- [ ] **Step 3: Run linter**

```bash
npm run lint 2>&1 | head -30
```

Expected: no errors (warnings tolerated if pre-existing).

- [ ] **Step 4: Manual smoke test**

Run the full local stack:

```bash
docker compose up -d
```

Then in a browser:
1. Log in as a user with a configured geofence
2. Navigate to Dashboard
3. Click "Enable Geofence Tracking"
4. Allow location permission when prompted
5. Verify the modal opens with a distance + estimated minutes
6. Adjust the time (try a small value like 2 minutes)
7. Click "Start Timer"
8. Verify the button shows "Opens in 1:59 (tap to cancel)" and counts down
9. Reload the page and verify the countdown is still showing (server state persisted)
10. Click the countdown button to cancel; verify it returns to "Enable Geofence Tracking"
11. Re-schedule for ~2 minutes; let it expire; verify the servo fires (or, with no physical ESP, verify the DB shows `status=fired` and `is_active=false`)

- [ ] **Step 5: Capture as teachable moment**

After the manual smoke test passes, ask Claude to add a teachable-moment memory file documenting:
- The shape of the architectural decision (web → time-based, native → location-based)
- The trade-offs (security regression mitigated by 3hr cap + future session invalidation)
- The interview talk-track for "tell me about a product trade-off you made"

The memory file path will be `~/.claude/projects/-Users-mr-casanova-Code-wolf/memory/moment-2026-06-13-time-based-trigger.md`.

---

## Done

When all tasks pass: web users get a popup → adjustable timer → live countdown UI; backend persists the schedule, fires a delayed job that triggers the servo. The future native app will use `/check` for live location triggers without touching the time-based code path.
