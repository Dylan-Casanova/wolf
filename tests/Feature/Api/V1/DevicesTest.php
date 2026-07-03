<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Models\Device;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DevicesTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function index_returns_authenticated_users_devices(): void
    {
        $user = User::factory()->create();
        Device::factory()->count(2)->create(['user_id' => $user->id]);
        // Another user's device must not appear in the response.
        $otherUser = User::factory()->create();
        Device::factory()->create(['user_id' => $otherUser->id]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/devices');

        $response->assertOk();
        $response->assertJsonCount(2, 'data');
        $response->assertJsonStructure([
            'data' => [
                ['id', 'name', 'device_id', 'type', 'is_online', 'last_seen_at', 'meta'],
            ],
        ]);
    }

    #[Test]
    public function claim_succeeds_and_returns_device_on_unclaimed_device(): void
    {
        $user = User::factory()->create();
        $device = Device::factory()->create(['user_id' => null]);

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/devices/claim', [
            'device_id' => $device->device_id,
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.device_id', $device->device_id);
        $this->assertEquals($user->id, $device->fresh()->user_id);
    }

    #[Test]
    public function claim_returns_404_when_device_id_does_not_exist(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $response = $this->postJson('/api/v1/devices/claim', [
            'device_id' => 'nonexistent-device-id',
        ]);

        $response->assertStatus(404);
        $response->assertJson(['message' => 'Device not found.']);
    }

    #[Test]
    public function claim_returns_422_when_user_already_owns_device(): void
    {
        $user = User::factory()->create();
        $device = Device::factory()->create(['user_id' => $user->id]);

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/devices/claim', [
            'device_id' => $device->device_id,
        ]);

        $response->assertStatus(422);
        $response->assertJson(['message' => 'You already own this device.']);
    }

    #[Test]
    public function claim_returns_422_when_device_is_claimed_by_someone_else(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $device = Device::factory()->create(['user_id' => $otherUser->id]);

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/devices/claim', [
            'device_id' => $device->device_id,
        ]);

        $response->assertStatus(422);
        $response->assertJson(['message' => 'Device is already claimed.']);
    }
}
