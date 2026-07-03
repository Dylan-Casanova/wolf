<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Console\Commands\MqttListenCommand;
use App\Events\DeviceStatusChanged;
use App\Models\Device;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class MqttListenCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_handle_status_online_marks_device_online(): void
    {
        Event::fake([DeviceStatusChanged::class]);

        $device = Device::factory()->create([
            'device_id' => 'esp32-001',
            'is_online' => false,
        ]);

        $command = new MqttListenCommand;
        $command->handleMessage('wolf/esp32-001/status', 'online');

        $device->refresh();
        $this->assertTrue($device->is_online);
        $this->assertNotNull($device->last_seen_at);

        Event::assertDispatched(DeviceStatusChanged::class);
    }

    public function test_handle_status_offline_marks_device_offline(): void
    {
        Event::fake([DeviceStatusChanged::class]);

        $device = Device::factory()->create([
            'device_id' => 'esp32-001',
            'is_online' => true,
            'last_seen_at' => now(),
        ]);

        $command = new MqttListenCommand;
        $command->handleMessage('wolf/esp32-001/status', 'offline');

        $device->refresh();
        $this->assertFalse($device->is_online);

        Event::assertDispatched(DeviceStatusChanged::class);
    }

    public function test_handle_heartbeat_updates_meta_and_last_seen(): void
    {
        Event::fake([DeviceStatusChanged::class]);

        $device = Device::factory()->create([
            'device_id' => 'esp32-001',
            'is_online' => true,
        ]);

        $payload = json_encode(['rssi' => -45, 'free_heap' => 120000, 'uptime' => 3600]);

        $command = new MqttListenCommand;
        $command->handleMessage('wolf/esp32-001/heartbeat', $payload);

        $device->refresh();
        $this->assertTrue($device->is_online);
        $this->assertEquals(-45, $device->meta['rssi']);
        $this->assertEquals(120000, $device->meta['free_heap']);

        Event::assertDispatched(DeviceStatusChanged::class);
    }

    public function test_unknown_device_id_is_ignored(): void
    {
        Event::fake([DeviceStatusChanged::class]);

        $command = new MqttListenCommand;
        $command->handleMessage('wolf/unknown-device/status', 'online');

        Event::assertNotDispatched(DeviceStatusChanged::class);
    }
}
