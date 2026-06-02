<?php

namespace Tests\Feature;

use App\Events\DeviceStatusChanged;
use App\Models\Device;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class CheckStaleDevicesCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_marks_stale_devices_offline(): void
    {
        Event::fake([DeviceStatusChanged::class]);

        $stale = Device::factory()->create([
            'is_online' => true,
            'last_seen_at' => now()->subMinutes(20),
        ]);

        $fresh = Device::factory()->create([
            'is_online' => true,
            'last_seen_at' => now()->subMinutes(2),
        ]);

        $this->artisan('devices:check-stale')->assertSuccessful();

        $this->assertFalse($stale->fresh()->is_online);
        $this->assertTrue($fresh->fresh()->is_online);

        Event::assertDispatched(DeviceStatusChanged::class, 1);
    }

    public function test_ignores_already_offline_devices(): void
    {
        Event::fake([DeviceStatusChanged::class]);

        Device::factory()->create([
            'is_online' => false,
            'last_seen_at' => now()->subMinutes(60),
        ]);

        $this->artisan('devices:check-stale')->assertSuccessful();

        Event::assertNotDispatched(DeviceStatusChanged::class);
    }

    public function test_marks_online_devices_with_null_last_seen_as_stale(): void
    {
        Event::fake([DeviceStatusChanged::class]);

        $device = Device::factory()->create([
            'is_online' => true,
            'last_seen_at' => null,
        ]);

        $this->artisan('devices:check-stale')->assertSuccessful();

        $this->assertFalse($device->fresh()->is_online);
        Event::assertDispatched(DeviceStatusChanged::class, 1);
    }
}
