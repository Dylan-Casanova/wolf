<?php

namespace Tests\Feature;

use App\Models\Device;
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
        Device::factory()->create(['user_id' => $user->id]);

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
        $device = Device::factory()->create(['user_id' => $user->id]);

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
        $device = Device::factory()->create();

        $response = $this->actingAs($admin)->delete("/devices/{$device->id}");

        $response->assertRedirect('/devices');
        $this->assertDatabaseMissing('devices', ['id' => $device->id]);
    }

    public function test_admin_can_regenerate_device_token(): void
    {
        $admin = User::factory()->admin()->create();
        $device = Device::factory()->create();
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
        Device::factory()->create(['device_id' => 'esp32-001', 'user_id' => $user1->id]);

        $response = $this->actingAs($admin)->post('/devices', [
            'name' => 'Another Cam',
            'device_id' => 'esp32-001',
            'user_id' => $user2->id,
            'type' => 'esp32-cam',
        ]);

        $response->assertSessionHasErrors('device_id');
    }
}
