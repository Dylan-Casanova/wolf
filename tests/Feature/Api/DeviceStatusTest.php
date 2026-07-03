<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Device;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
