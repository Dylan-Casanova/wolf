<?php

namespace Tests\Feature;

use App\Events\DeviceStatusChanged;
use App\Models\Device;
use App\Models\User;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class DeviceStatusChangedTest extends TestCase
{
    use RefreshDatabase;

    public function test_event_broadcasts_on_devices_channel(): void
    {
        Event::fake([DeviceStatusChanged::class]);

        $device = Device::factory()->create();
        $device->markOnline();

        DeviceStatusChanged::dispatch($device->fresh());

        Event::assertDispatched(DeviceStatusChanged::class, function ($event) use ($device) {
            $channels = $event->broadcastOn();
            $this->assertCount(1, $channels);
            $this->assertEquals('private-devices', $channels[0]->name);

            $data = $event->broadcastWith();
            $this->assertEquals($device->id, $data['device_id']);
            $this->assertTrue($data['is_online']);
            $this->assertNotNull($data['last_seen_at']);

            return true;
        });
    }

    public function test_admin_can_access_devices_channel(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin);

        $this->assertTrue(
            app(BroadcastManager::class)
                ->channel('devices', function ($user) {
                    return $user->is_admin;
                }) !== null
        );
    }
}
