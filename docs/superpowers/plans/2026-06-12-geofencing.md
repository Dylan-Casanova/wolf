# Geofencing Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Allow each user to create a rectangular geofence that auto-triggers their ESP8266 garage servo when they enter the perimeter (browser geolocation, adaptive polling).

**Architecture:** A new `geofences` table stores rectangle bounds (north/south lat, east/west lng) per user. The frontend uses Leaflet with react-leaflet for map display and editable rectangles, Nominatim for address search. A `useGeolocation` hook polls the browser Geolocation API at adaptive intervals (30s when far, 10s within 2 miles) and POSTs coordinates to a `/api/geo-fences/check` endpoint that tests containment and triggers the servo if inside. After triggering, the server sets `is_active=false` so the user must manually re-enable tracking.

**Tech Stack:** Laravel 11, Inertia.js/React, Leaflet + react-leaflet + @react-leaflet/core, Nominatim geocoding, MySQL, Browser Geolocation API

**Existing scaffolding to rework:** Migration `2026_04_14_151813_create_geo_fences_table.php` (circle-based, needs rectangle bounds), Model `app/Models/GeoFence.php` (has Haversine `contains()`, needs rectangle math), Controller `app/Http/Controllers/GeoFenceController.php` (all stubs returning 501), API routes in `routes/api.php` (already registered).

---

## File Map

| Action | File | Responsibility |
|--------|------|----------------|
| Modify | `database/migrations/2026_04_14_151813_create_geo_fences_table.php` | Rectangle bounds schema |
| Modify | `app/Models/GeoFence.php` | Rectangle model, `contains()` with bounds check |
| Create | `database/factories/GeoFenceFactory.php` | Test factory |
| Modify | `app/Http/Controllers/GeoFenceController.php` | Full CRUD + toggle + check endpoint |
| Modify | `routes/api.php` | Add toggle route |
| Modify | `routes/web.php` | Add Inertia geofence page route, pass geofence to dashboard |
| Modify | `resources/js/types/index.d.ts` | Add `Geofence` TypeScript interface |
| Create | `resources/js/Pages/Geofence/Index.tsx` | Geofence page — map, address search, rectangle editing, toggle |
| Create | `resources/js/Components/GeofenceMap.tsx` | Leaflet map + editable rectangle component |
| Create | `resources/js/Components/AddressSearch.tsx` | Nominatim address search input |
| Create | `resources/js/Components/GeofenceToggle.tsx` | Enable/disable toggle button (reused on Dashboard + Geofence page) |
| Create | `resources/js/hooks/useGeolocation.ts` | Adaptive geolocation polling hook |
| Modify | `resources/js/Pages/Dashboard.tsx` | Add geofence toggle widget |
| Modify | `resources/js/Layouts/AuthenticatedLayout.tsx` | Add "Geofence" NavLink |
| Create | `tests/Feature/GeoFenceTest.php` | Backend tests for CRUD, toggle, check |
| Modify | `app/Models/User.php` | Add `geofence()` HasOne relationship |

---

### Task 1: Update Migration — Rectangle Bounds Schema

**Files:**
- Modify: `database/migrations/2026_04_14_151813_create_geo_fences_table.php`

- [ ] **Step 1: Rewrite the migration to use rectangle bounds**

Replace the entire `up()` method. The new schema stores rectangle bounds instead of center+radius. One geofence per user enforced by unique constraint.

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('geo_fences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->decimal('north_lat', 10, 7);
            $table->decimal('south_lat', 10, 7);
            $table->decimal('east_lng', 10, 7);
            $table->decimal('west_lng', 10, 7);
            $table->boolean('is_active')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('geo_fences');
    }
};
```

- [ ] **Step 2: Verify migration compiles**

Run: `docker compose exec app php artisan migrate:fresh --seed` (or locally if available)
Expected: Table created successfully with new columns.

- [ ] **Step 3: Commit**

```
feat: update geo_fences migration to rectangle bounds schema
```

---

### Task 2: Update GeoFence Model + Factory + User Relationship

**Files:**
- Modify: `app/Models/GeoFence.php`
- Create: `database/factories/GeoFenceFactory.php`
- Modify: `app/Models/User.php`

- [ ] **Step 1: Rewrite GeoFence model**

Replace entire contents of `app/Models/GeoFence.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GeoFence extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'north_lat',
        'south_lat',
        'east_lng',
        'west_lng',
        'is_active',
    ];

    protected $casts = [
        'north_lat' => 'float',
        'south_lat' => 'float',
        'east_lng' => 'float',
        'west_lng' => 'float',
        'is_active' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function contains(float $lat, float $lng): bool
    {
        return $lat <= $this->north_lat
            && $lat >= $this->south_lat
            && $lng <= $this->east_lng
            && $lng >= $this->west_lng;
    }

    public function centerLat(): float
    {
        return ($this->north_lat + $this->south_lat) / 2;
    }

    public function centerLng(): float
    {
        return ($this->east_lng + $this->west_lng) / 2;
    }

    public function distanceFromCenter(float $lat, float $lng): float
    {
        $earthRadius = 6371000;
        $centerLat = $this->centerLat();
        $centerLng = $this->centerLng();

        $dLat = deg2rad($lat - $centerLat);
        $dLng = deg2rad($lng - $centerLng);

        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($centerLat)) * cos(deg2rad($lat)) * sin($dLng / 2) ** 2;

        return $earthRadius * 2 * asin(sqrt($a));
    }
}
```

- [ ] **Step 2: Create GeoFence factory**

Create `database/factories/GeoFenceFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\GeoFence;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class GeoFenceFactory extends Factory
{
    protected $model = GeoFence::class;

    public function definition(): array
    {
        $lat = $this->faker->latitude(29.0, 30.0);
        $lng = $this->faker->longitude(-98.5, -97.5);

        return [
            'user_id' => User::factory(),
            'north_lat' => $lat + 0.002,
            'south_lat' => $lat - 0.002,
            'east_lng' => $lng + 0.003,
            'west_lng' => $lng - 0.003,
            'is_active' => false,
        ];
    }

    public function active(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => true,
        ]);
    }
}
```

- [ ] **Step 3: Add `geofence()` relationship to User model**

In `app/Models/User.php`, add the import and relationship method:

Add import at top:
```php
use Illuminate\Database\Eloquent\Relations\HasOne;
```

Add method after `devices()`:
```php
public function geofence(): HasOne
{
    return $this->hasOne(GeoFence::class);
}
```

- [ ] **Step 4: Commit**

```
feat: update GeoFence model for rectangle bounds, add factory and user relationship
```

---

### Task 3: Implement GeoFenceController + Toggle Route

**Files:**
- Modify: `app/Http/Controllers/GeoFenceController.php`
- Modify: `routes/api.php`

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/GeoFenceTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Contracts\DeviceInterface;
use App\Models\GeoFence;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class GeoFenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_create_geofence(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/geo-fences', [
            'north_lat' => 29.4260,
            'south_lat' => 29.4240,
            'east_lng' => -98.4900,
            'west_lng' => -98.4930,
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('geo_fences', ['user_id' => $user->id]);
    }

    public function test_user_cannot_create_second_geofence(): void
    {
        $user = User::factory()->create();
        GeoFence::factory()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/geo-fences', [
            'north_lat' => 29.4260,
            'south_lat' => 29.4240,
            'east_lng' => -98.4900,
            'west_lng' => -98.4930,
        ]);

        $response->assertStatus(409);
    }

    public function test_user_can_update_geofence(): void
    {
        $user = User::factory()->create();
        $fence = GeoFence::factory()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user, 'sanctum')->putJson("/api/geo-fences/{$fence->id}", [
            'north_lat' => 30.0000,
            'south_lat' => 29.9990,
            'east_lng' => -97.0000,
            'west_lng' => -97.0010,
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('geo_fences', ['id' => $fence->id, 'north_lat' => 30.0000]);
    }

    public function test_user_cannot_update_another_users_geofence(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $fence = GeoFence::factory()->create(['user_id' => $other->id]);

        $response = $this->actingAs($user, 'sanctum')->putJson("/api/geo-fences/{$fence->id}", [
            'north_lat' => 30.0,
            'south_lat' => 29.0,
            'east_lng' => -97.0,
            'west_lng' => -98.0,
        ]);

        $response->assertStatus(403);
    }

    public function test_user_can_delete_geofence(): void
    {
        $user = User::factory()->create();
        $fence = GeoFence::factory()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user, 'sanctum')->deleteJson("/api/geo-fences/{$fence->id}");

        $response->assertOk();
        $this->assertDatabaseMissing('geo_fences', ['id' => $fence->id]);
    }

    public function test_user_can_toggle_geofence(): void
    {
        $user = User::factory()->create();
        $fence = GeoFence::factory()->create(['user_id' => $user->id, 'is_active' => false]);

        $response = $this->actingAs($user, 'sanctum')->postJson("/api/geo-fences/{$fence->id}/toggle");

        $response->assertOk();
        $response->assertJson(['is_active' => true]);

        $response = $this->actingAs($user, 'sanctum')->postJson("/api/geo-fences/{$fence->id}/toggle");

        $response->assertOk();
        $response->assertJson(['is_active' => false]);
    }

    public function test_check_inside_geofence_triggers_servo(): void
    {
        $user = User::factory()->create();
        $user->devices()->create([
            'name' => 'Test ESP',
            'device_id' => 'ESP8266-001',
            'type' => 'esp8266',
            'is_online' => true,
        ]);
        $fence = GeoFence::factory()->active()->create([
            'user_id' => $user->id,
            'north_lat' => 29.4260,
            'south_lat' => 29.4240,
            'east_lng' => -98.4900,
            'west_lng' => -98.4930,
        ]);

        $mock = Mockery::mock(DeviceInterface::class);
        $mock->shouldReceive('triggerServo')->once()->andReturn(true);
        $this->app->instance(DeviceInterface::class, $mock);

        $response = $this->actingAs($user, 'sanctum')->postJson("/api/geo-fences/{$fence->id}/check", [
            'lat' => 29.4250,
            'lng' => -98.4915,
        ]);

        $response->assertOk();
        $response->assertJson(['triggered' => true]);
        $this->assertFalse($fence->fresh()->is_active);
    }

    public function test_check_outside_geofence_does_not_trigger(): void
    {
        $user = User::factory()->create();
        $fence = GeoFence::factory()->active()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user, 'sanctum')->postJson("/api/geo-fences/{$fence->id}/check", [
            'lat' => 0.0,
            'lng' => 0.0,
        ]);

        $response->assertOk();
        $response->assertJson(['triggered' => false]);
        $this->assertTrue($fence->fresh()->is_active);
    }

    public function test_check_inactive_geofence_does_not_trigger(): void
    {
        $user = User::factory()->create();
        $fence = GeoFence::factory()->create(['user_id' => $user->id, 'is_active' => false]);

        $response = $this->actingAs($user, 'sanctum')->postJson("/api/geo-fences/{$fence->id}/check", [
            'lat' => $fence->north_lat - 0.001,
            'lng' => $fence->west_lng + 0.001,
        ]);

        $response->assertOk();
        $response->assertJson(['triggered' => false]);
    }

    public function test_check_returns_distance_from_center(): void
    {
        $user = User::factory()->create();
        $fence = GeoFence::factory()->active()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user, 'sanctum')->postJson("/api/geo-fences/{$fence->id}/check", [
            'lat' => 0.0,
            'lng' => 0.0,
        ]);

        $response->assertOk();
        $response->assertJsonStructure(['triggered', 'distance_meters']);
    }

    public function test_index_returns_user_geofence(): void
    {
        $user = User::factory()->create();
        $fence = GeoFence::factory()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/geo-fences');

        $response->assertOk();
        $response->assertJsonFragment(['id' => $fence->id]);
    }

    public function test_guest_cannot_access_geofence_endpoints(): void
    {
        $response = $this->getJson('/api/geo-fences');
        $response->assertUnauthorized();
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `docker compose exec app php artisan test --filter=GeoFenceTest`
Expected: All tests FAIL (controller returns 501 stubs).

- [ ] **Step 3: Implement the controller**

Replace entire contents of `app/Http/Controllers/GeoFenceController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Contracts\DeviceInterface;
use App\Models\GeoFence;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GeoFenceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $geofence = $request->user()->geofence;

        return response()->json($geofence ? [$geofence] : []);
    }

    public function store(Request $request): JsonResponse
    {
        if ($request->user()->geofence) {
            return response()->json(['message' => 'Geofence already exists.'], 409);
        }

        $validated = $request->validate([
            'north_lat' => ['required', 'numeric', 'between:-90,90'],
            'south_lat' => ['required', 'numeric', 'between:-90,90'],
            'east_lng' => ['required', 'numeric', 'between:-180,180'],
            'west_lng' => ['required', 'numeric', 'between:-180,180'],
        ]);

        $geofence = $request->user()->geofence()->create($validated);

        return response()->json($geofence, 201);
    }

    public function update(Request $request, GeoFence $geoFence): JsonResponse
    {
        if ($geoFence->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $validated = $request->validate([
            'north_lat' => ['required', 'numeric', 'between:-90,90'],
            'south_lat' => ['required', 'numeric', 'between:-90,90'],
            'east_lng' => ['required', 'numeric', 'between:-180,180'],
            'west_lng' => ['required', 'numeric', 'between:-180,180'],
        ]);

        $geoFence->update($validated);

        return response()->json($geoFence);
    }

    public function destroy(Request $request, GeoFence $geoFence): JsonResponse
    {
        if ($geoFence->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $geoFence->delete();

        return response()->json(['message' => 'Geofence deleted.']);
    }

    public function toggle(Request $request, GeoFence $geoFence): JsonResponse
    {
        if ($geoFence->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $geoFence->update(['is_active' => ! $geoFence->is_active]);

        return response()->json(['is_active' => $geoFence->fresh()->is_active]);
    }

    public function check(Request $request, GeoFence $geoFence, DeviceInterface $device): JsonResponse
    {
        if ($geoFence->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $validated = $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
        ]);

        $distance = $geoFence->distanceFromCenter($validated['lat'], $validated['lng']);
        $inside = $geoFence->is_active && $geoFence->contains($validated['lat'], $validated['lng']);

        if ($inside) {
            $esp = $request->user()->devices()->where('type', 'esp8266')->first();

            if ($esp) {
                $device->triggerServo($esp);
            }

            $geoFence->update(['is_active' => false]);
        }

        return response()->json([
            'triggered' => $inside,
            'distance_meters' => round($distance),
        ]);
    }
}
```

- [ ] **Step 4: Add toggle route to `routes/api.php`**

In `routes/api.php`, inside the `auth:sanctum` group, after the existing geo-fence routes, add:

```php
Route::post('geo-fences/{geoFence}/toggle', [GeoFenceController::class, 'toggle']);
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `docker compose exec app php artisan test --filter=GeoFenceTest`
Expected: All 11 tests PASS.

- [ ] **Step 6: Commit**

```
feat: implement GeoFence controller — CRUD, toggle, proximity check with servo trigger
```

---

### Task 4: Add TypeScript Types + Geofence Route in web.php

**Files:**
- Modify: `resources/js/types/index.d.ts`
- Modify: `routes/web.php`

- [ ] **Step 1: Add Geofence TypeScript interface**

In `resources/js/types/index.d.ts`, add after the `Device` interface:

```typescript
export interface Geofence {
    id: number;
    user_id: number;
    north_lat: number;
    south_lat: number;
    east_lng: number;
    west_lng: number;
    is_active: boolean;
}
```

- [ ] **Step 2: Add Inertia route for geofence page**

In `routes/web.php`, add import at top:

```php
use App\Http\Controllers\GeoFenceController;
```

Inside the `auth, verified` middleware group (after the claim routes, before the admin group), add:

```php
Route::get('/geofence', function () {
    $geofence = auth()->user()->geofence;

    return Inertia::render('Geofence/Index', [
        'geofence' => $geofence,
    ]);
})->name('geofence');
```

- [ ] **Step 3: Update dashboard route to pass geofence data**

In `routes/web.php`, modify the dashboard route closure to also pass the geofence:

```php
Route::get('/dashboard', function () {
    $user = auth()->user();
    $devices = $user->devices()->get()->map(fn ($device) => [
        'id' => $device->id,
        'name' => $device->name,
        'device_id' => $device->device_id,
        'type' => $device->type->value,
        'is_online' => $device->is_online,
    ]);

    return Inertia::render('Dashboard', [
        'devices' => $devices,
        'geofence' => $user->geofence,
    ]);
})->name('dashboard');
```

- [ ] **Step 4: Commit**

```
feat: add Geofence TypeScript type, Inertia routes for geofence page and dashboard
```

---

### Task 5: Install Leaflet Dependencies

**Files:**
- Modify: `package.json`

- [ ] **Step 1: Install leaflet, react-leaflet, and types**

Run:

```bash
npm install leaflet react-leaflet
npm install -D @types/leaflet
```

- [ ] **Step 2: Verify package.json was updated**

Confirm `leaflet`, `react-leaflet` are in dependencies and `@types/leaflet` in devDependencies.

- [ ] **Step 3: Commit**

```
feat: add leaflet and react-leaflet dependencies
```

---

### Task 6: Create AddressSearch Component

**Files:**
- Create: `resources/js/Components/AddressSearch.tsx`

- [ ] **Step 1: Create the AddressSearch component**

Create `resources/js/Components/AddressSearch.tsx`:

```tsx
import { useCallback, useRef, useState } from 'react';

interface SearchResult {
    display_name: string;
    lat: string;
    lon: string;
    boundingbox: [string, string, string, string];
}

interface AddressSearchProps {
    onSelect: (lat: number, lng: number) => void;
}

export default function AddressSearch({ onSelect }: AddressSearchProps) {
    const [query, setQuery] = useState('');
    const [results, setResults] = useState<SearchResult[]>([]);
    const [loading, setLoading] = useState(false);
    const debounceRef = useRef<ReturnType<typeof setTimeout> | null>(null);

    const search = useCallback(
        (value: string) => {
            if (debounceRef.current) clearTimeout(debounceRef.current);
            if (value.length < 3) {
                setResults([]);
                return;
            }

            debounceRef.current = setTimeout(async () => {
                setLoading(true);
                try {
                    const res = await fetch(
                        `https://nominatim.openstreetmap.org/search?format=json&q=${encodeURIComponent(value)}&limit=5`,
                    );
                    const data: SearchResult[] = await res.json();
                    setResults(data);
                } catch {
                    setResults([]);
                } finally {
                    setLoading(false);
                }
            }, 400);
        },
        [],
    );

    const handleSelect = (result: SearchResult) => {
        setQuery(result.display_name);
        setResults([]);
        onSelect(parseFloat(result.lat), parseFloat(result.lon));
    };

    return (
        <div className="relative">
            <input
                type="text"
                value={query}
                onChange={(e) => {
                    setQuery(e.target.value);
                    search(e.target.value);
                }}
                placeholder="Search address..."
                className="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
            />
            {loading && (
                <div className="absolute right-3 top-2.5 text-xs text-gray-400">
                    Searching...
                </div>
            )}
            {results.length > 0 && (
                <ul className="absolute z-[1000] mt-1 max-h-60 w-full overflow-auto rounded-md border border-gray-200 bg-white shadow-lg">
                    {results.map((r, i) => (
                        <li
                            key={i}
                            onClick={() => handleSelect(r)}
                            className="cursor-pointer px-3 py-2 text-sm text-gray-700 hover:bg-indigo-50"
                        >
                            {r.display_name}
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}
```

- [ ] **Step 2: Commit**

```
feat: add AddressSearch component with Nominatim geocoding
```

---

### Task 7: Create GeofenceMap Component

**Files:**
- Create: `resources/js/Components/GeofenceMap.tsx`

- [ ] **Step 1: Create the GeofenceMap component**

Create `resources/js/Components/GeofenceMap.tsx`:

```tsx
import type { Geofence } from '@/types';
import L from 'leaflet';
import 'leaflet/dist/leaflet.css';
import { useCallback, useEffect, useRef, useState } from 'react';
import { MapContainer, Rectangle, TileLayer, useMap } from 'react-leaflet';

interface GeofenceMapProps {
    geofence: Geofence | null;
    center: [number, number] | null;
    onBoundsChange: (bounds: {
        north_lat: number;
        south_lat: number;
        east_lng: number;
        west_lng: number;
    }) => void;
}

function MapCenter({ center }: { center: [number, number] }) {
    const map = useMap();
    useEffect(() => {
        map.setView(center, 16);
    }, [center, map]);
    return null;
}

function EditableRectangle({
    bounds,
    onBoundsChange,
}: {
    bounds: L.LatLngBoundsExpression;
    onBoundsChange: GeofenceMapProps['onBoundsChange'];
}) {
    const rectRef = useRef<L.Rectangle | null>(null);
    const map = useMap();

    const updateBounds = useCallback(() => {
        if (!rectRef.current) return;
        const b = rectRef.current.getBounds();
        onBoundsChange({
            north_lat: parseFloat(b.getNorth().toFixed(7)),
            south_lat: parseFloat(b.getSouth().toFixed(7)),
            east_lng: parseFloat(b.getEast().toFixed(7)),
            west_lng: parseFloat(b.getWest().toFixed(7)),
        });
    }, [onBoundsChange]);

    useEffect(() => {
        if (rectRef.current) {
            map.removeLayer(rectRef.current);
        }

        const rect = L.rectangle(bounds, {
            color: '#4f46e5',
            weight: 2,
            fillOpacity: 0.15,
        }).addTo(map);

        // @ts-expect-error Leaflet.Editable or transform not typed
        if (rect.editing) {
            // @ts-expect-error
            rect.editing.enable();
        } else if (L.Edit?.Rectangle) {
            // @ts-expect-error
            new L.Edit.Rectangle(rect).enable();
        }

        rect.on('edit', updateBounds);
        rect.on('editdrag', updateBounds);

        rectRef.current = rect;
        updateBounds();

        return () => {
            rect.off('edit', updateBounds);
            rect.off('editdrag', updateBounds);
            if (rectRef.current) {
                map.removeLayer(rectRef.current);
            }
        };
    }, [bounds, map, updateBounds]);

    return null;
}

export default function GeofenceMap({
    geofence,
    center,
    onBoundsChange,
}: GeofenceMapProps) {
    const defaultCenter: [number, number] = [29.4241, -98.4936];

    const mapCenter = geofence
        ? ([
              (geofence.north_lat + geofence.south_lat) / 2,
              (geofence.east_lng + geofence.west_lng) / 2,
          ] as [number, number])
        : center || defaultCenter;

    const [rectBounds, setRectBounds] = useState<L.LatLngBoundsExpression>(
        geofence
            ? [
                  [geofence.south_lat, geofence.west_lng],
                  [geofence.north_lat, geofence.east_lng],
              ]
            : center
              ? [
                    [center[0] - 0.001, center[1] - 0.0015],
                    [center[0] + 0.001, center[1] + 0.0015],
                ]
              : [
                    [defaultCenter[0] - 0.001, defaultCenter[1] - 0.0015],
                    [defaultCenter[0] + 0.001, defaultCenter[1] + 0.0015],
                ],
    );

    useEffect(() => {
        if (center && !geofence) {
            setRectBounds([
                [center[0] - 0.001, center[1] - 0.0015],
                [center[0] + 0.001, center[1] + 0.0015],
            ]);
        }
    }, [center, geofence]);

    return (
        <MapContainer
            center={mapCenter}
            zoom={16}
            className="h-[400px] w-full rounded-lg"
        >
            <TileLayer
                attribution='&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>'
                url="https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png"
            />
            {center && <MapCenter center={center} />}
            <EditableRectangle
                bounds={rectBounds}
                onBoundsChange={onBoundsChange}
            />
        </MapContainer>
    );
}
```

- [ ] **Step 2: Commit**

```
feat: add GeofenceMap component with Leaflet editable rectangle
```

---

### Task 8: Create GeofenceToggle Component

**Files:**
- Create: `resources/js/Components/GeofenceToggle.tsx`

- [ ] **Step 1: Create the toggle component**

Create `resources/js/Components/GeofenceToggle.tsx`:

```tsx
import { useState } from 'react';
import axios from 'axios';

interface GeofenceToggleProps {
    geofenceId: number;
    initialActive: boolean;
    onToggle?: (isActive: boolean) => void;
}

export default function GeofenceToggle({
    geofenceId,
    initialActive,
    onToggle,
}: GeofenceToggleProps) {
    const [isActive, setIsActive] = useState(initialActive);
    const [loading, setLoading] = useState(false);

    const toggle = async () => {
        setLoading(true);
        try {
            const response = await axios.post(
                `/api/geo-fences/${geofenceId}/toggle`,
            );
            const newState = response.data.is_active;
            setIsActive(newState);
            onToggle?.(newState);
        } catch {
            // revert on failure
        } finally {
            setLoading(false);
        }
    };

    return (
        <button
            onClick={toggle}
            disabled={loading}
            className={`inline-flex items-center gap-2 rounded-lg px-4 py-2 text-sm font-semibold transition-colors ${
                isActive
                    ? 'bg-green-600 text-white hover:bg-green-700'
                    : 'bg-gray-200 text-gray-700 hover:bg-gray-300'
            } disabled:opacity-50`}
        >
            <span
                className={`h-2.5 w-2.5 rounded-full ${
                    isActive ? 'animate-pulse bg-white' : 'bg-gray-400'
                }`}
            />
            {loading
                ? 'Updating...'
                : isActive
                  ? 'Geofencing Active'
                  : 'Geofencing Off'}
        </button>
    );
}
```

- [ ] **Step 2: Commit**

```
feat: add GeofenceToggle component
```

---

### Task 9: Create useGeolocation Hook

**Files:**
- Create: `resources/js/hooks/useGeolocation.ts`

- [ ] **Step 1: Create the adaptive geolocation hook**

Create `resources/js/hooks/useGeolocation.ts`:

```typescript
import { useCallback, useEffect, useRef, useState } from 'react';
import axios from 'axios';

const MILES_TO_METERS = 1609.34;
const CLOSE_THRESHOLD = 2 * MILES_TO_METERS;
const FAR_INTERVAL = 30000;
const CLOSE_INTERVAL = 10000;

interface UseGeolocationOptions {
    geofenceId: number;
    isActive: boolean;
    onTriggered: () => void;
}

export default function useGeolocation({
    geofenceId,
    isActive,
    onTriggered,
}: UseGeolocationOptions) {
    const [tracking, setTracking] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const intervalRef = useRef<ReturnType<typeof setTimeout> | null>(null);
    const currentInterval = useRef(FAR_INTERVAL);

    const checkPosition = useCallback(
        async (position: GeolocationPosition) => {
            try {
                const response = await axios.post(
                    `/api/geo-fences/${geofenceId}/check`,
                    {
                        lat: position.coords.latitude,
                        lng: position.coords.longitude,
                    },
                );

                if (response.data.triggered) {
                    setTracking(false);
                    onTriggered();
                    return;
                }

                const distance: number = response.data.distance_meters;
                const newInterval =
                    distance <= CLOSE_THRESHOLD
                        ? CLOSE_INTERVAL
                        : FAR_INTERVAL;

                if (newInterval !== currentInterval.current) {
                    currentInterval.current = newInterval;
                }
            } catch {
                setError('Failed to check position');
            }
        },
        [geofenceId, onTriggered],
    );

    const poll = useCallback(() => {
        navigator.geolocation.getCurrentPosition(
            (position) => {
                checkPosition(position);
            },
            (err) => {
                setError(err.message);
            },
            { enableHighAccuracy: true, timeout: 10000 },
        );
    }, [checkPosition]);

    useEffect(() => {
        if (!isActive || !tracking) {
            if (intervalRef.current) {
                clearInterval(intervalRef.current);
                intervalRef.current = null;
            }
            return;
        }

        if (!navigator.geolocation) {
            setError('Geolocation not supported');
            return;
        }

        poll();

        intervalRef.current = setInterval(() => {
            poll();
        }, currentInterval.current);

        return () => {
            if (intervalRef.current) {
                clearInterval(intervalRef.current);
                intervalRef.current = null;
            }
        };
    }, [isActive, tracking, poll]);

    // Restart interval when polling rate changes
    useEffect(() => {
        if (!tracking || !isActive) return;

        if (intervalRef.current) {
            clearInterval(intervalRef.current);
        }

        intervalRef.current = setInterval(() => {
            poll();
        }, currentInterval.current);

        return () => {
            if (intervalRef.current) {
                clearInterval(intervalRef.current);
            }
        };
    }, [currentInterval.current]);

    return {
        tracking,
        startTracking: () => setTracking(true),
        stopTracking: () => setTracking(false),
        error,
    };
}
```

- [ ] **Step 2: Commit**

```
feat: add useGeolocation hook with adaptive polling (30s far, 10s close)
```

---

### Task 10: Create Geofence Page

**Files:**
- Create: `resources/js/Pages/Geofence/Index.tsx`

- [ ] **Step 1: Create the Geofence page**

Create `resources/js/Pages/Geofence/Index.tsx`:

```tsx
import AddressSearch from '@/Components/AddressSearch';
import GeofenceMap from '@/Components/GeofenceMap';
import GeofenceToggle from '@/Components/GeofenceToggle';
import useGeolocation from '@/hooks/useGeolocation';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Geofence } from '@/types';
import { Head, router } from '@inertiajs/react';
import axios from 'axios';
import { useCallback, useState } from 'react';

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

    const handleTriggered = useCallback(() => {
        router.reload();
    }, []);

    const { tracking, startTracking, stopTracking, error } = useGeolocation({
        geofenceId: geofence?.id ?? 0,
        isActive: geofence?.is_active ?? false,
        onTriggered: handleTriggered,
    });

    const handleAddressSelect = (lat: number, lng: number) => {
        setCenter([lat, lng]);
        setShowMap(true);
    };

    const handleSave = async () => {
        if (!bounds) return;
        setSaving(true);
        try {
            if (geofence) {
                await axios.put(`/api/geo-fences/${geofence.id}`, bounds);
            } else {
                await axios.post('/api/geo-fences', bounds);
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
        await axios.delete(`/api/geo-fences/${geofence.id}`);
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
                    <div className="overflow-hidden bg-white shadow-sm sm:rounded-lg">
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
                                        onBoundsChange={setBounds}
                                    />

                                    <div className="flex items-center justify-between">
                                        <div className="flex items-center gap-3">
                                            {geofence && (
                                                <GeofenceToggle
                                                    geofenceId={geofence.id}
                                                    initialActive={
                                                        geofence.is_active
                                                    }
                                                    onToggle={(active) => {
                                                        if (active)
                                                            startTracking();
                                                        else stopTracking();
                                                    }}
                                                />
                                            )}
                                            {tracking && (
                                                <span className="text-xs text-green-600">
                                                    Tracking location...
                                                </span>
                                            )}
                                            {error && (
                                                <span className="text-xs text-red-500">
                                                    {error}
                                                </span>
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

- [ ] **Step 2: Commit**

```
feat: add Geofence page with map, address search, CRUD, and location tracking
```

---

### Task 11: Add Geofence Toggle to Dashboard

**Files:**
- Modify: `resources/js/Pages/Dashboard.tsx`

- [ ] **Step 1: Add geofence toggle to Dashboard**

Update `resources/js/Pages/Dashboard.tsx`:

Add imports at top:
```tsx
import GeofenceToggle from '@/Components/GeofenceToggle';
import useGeolocation from '@/hooks/useGeolocation';
import { Geofence } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
```

Remove the existing `import { Head, Link } from '@inertiajs/react';` line.

Update the props interface:
```tsx
interface DashboardProps {
    devices: DeviceInfo[];
    geofence: Geofence | null;
}
```

Update the component signature and add geolocation hook:
```tsx
export default function Dashboard({ devices, geofence }: DashboardProps) {
    const streamRef = useRef<StreamViewHandle>(null);
    const wasStreamingRef = useRef(false);

    const { tracking, startTracking, stopTracking } = useGeolocation({
        geofenceId: geofence?.id ?? 0,
        isActive: geofence?.is_active ?? false,
        onTriggered: () => router.reload(),
    });
```

After the devices list (after the closing `</div>` of `flex flex-col gap-6` but before the closing fragment or parent div), add the geofence widget:

```tsx
{geofence && (
    <div className="overflow-hidden bg-white shadow-sm sm:rounded-lg">
        <div className="border-b border-gray-100 px-6 py-3">
            <h3 className="text-sm font-semibold text-gray-700">
                Geofence
            </h3>
        </div>
        <div className="flex items-center justify-between p-6">
            <GeofenceToggle
                geofenceId={geofence.id}
                initialActive={geofence.is_active}
                onToggle={(active) => {
                    if (active) startTracking();
                    else stopTracking();
                }}
            />
            {tracking && (
                <span className="text-xs text-green-600">
                    Tracking location...
                </span>
            )}
        </div>
    </div>
)}
```

- [ ] **Step 2: Commit**

```
feat: add geofence toggle widget to Dashboard
```

---

### Task 12: Add Geofence NavLink to AuthenticatedLayout

**Files:**
- Modify: `resources/js/Layouts/AuthenticatedLayout.tsx`

- [ ] **Step 1: Add Geofence NavLink**

In `resources/js/Layouts/AuthenticatedLayout.tsx`, add the Geofence NavLink after the "Claim Device" NavLink in the desktop nav (around line 42):

```tsx
<NavLink
    href={route('geofence')}
    active={route().current('geofence')}
>
    Geofence
</NavLink>
```

Also add the responsive version after the "Claim Device" ResponsiveNavLink (around line 160):

```tsx
<ResponsiveNavLink
    href={route('geofence')}
    active={route().current('geofence')}
>
    Geofence
</ResponsiveNavLink>
```

- [ ] **Step 2: Commit**

```
feat: add Geofence NavLink to authenticated layout
```

---

### Task 13: Integration Testing — Full Flow Verification

- [ ] **Step 1: Run all backend tests**

Run: `docker compose exec app php artisan test`
Expected: All tests pass including the new GeoFenceTest.

- [ ] **Step 2: Start dev server and verify the Geofence page**

Run: `docker compose up -d` (dev environment)

Navigate to `/geofence`:
- With no geofence: should show "No geofence configured" + address search
- Search an address → map appears with draggable rectangle
- Click "Create Perimeter" → saves, page reloads showing the map with toggle
- Adjust rectangle → click "Update Perimeter" → saves new bounds
- Toggle on → should see "Tracking location..." if geolocation is allowed
- Toggle off → tracking stops
- Delete → returns to empty state

Navigate to `/dashboard`:
- Should show geofence toggle widget below the device cards
- Toggle should work same as on the Geofence page

Check navbar: "Geofence" link should appear between "Claim Device" and "Devices" (for admin).

- [ ] **Step 3: Test geofence trigger flow**

1. Create a geofence around your current location (make the rectangle cover where you are)
2. Enable geofencing
3. The next location poll should detect you're inside → trigger the servo → auto-disable tracking
4. Verify the button state resets and `is_active` becomes false

- [ ] **Step 4: Commit any fixes**

```
fix: integration test fixes for geofencing
```

---

## Production Deployment

After all tasks are complete and tested locally:

```bash
bash /opt/wolf/deploy.sh
docker compose exec app php artisan migrate
```

The migration will update the `geo_fences` table schema. Since the table likely already exists in production from the old migration, you may need to drop and recreate it:

```bash
docker compose exec app php artisan tinker --execute="Schema::dropIfExists('geo_fences');"
docker compose exec app php artisan migrate
```
