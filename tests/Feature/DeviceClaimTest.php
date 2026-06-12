<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeviceClaimTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_claim_unclaimed_device(): void
    {
        $user = User::factory()->create();
        $device = Device::factory()->unclaimed()->create(['device_id' => 'ESP8266-001']);

        $response = $this->actingAs($user)->post('/devices/claim', [
            'device_id' => 'ESP8266-001',
        ]);

        $response->assertRedirect('/dashboard');
        $this->assertEquals($user->id, $device->fresh()->user_id);
    }

    public function test_user_cannot_claim_device_owned_by_another(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        Device::factory()->create(['device_id' => 'ESP8266-001', 'user_id' => $otherUser->id]);

        $response = $this->actingAs($user)->post('/devices/claim', [
            'device_id' => 'ESP8266-001',
        ]);

        $response->assertSessionHasErrors('device_id');
    }

    public function test_user_cannot_claim_device_they_already_own(): void
    {
        $user = User::factory()->create();
        Device::factory()->create(['device_id' => 'ESP8266-001', 'user_id' => $user->id]);

        $response = $this->actingAs($user)->post('/devices/claim', [
            'device_id' => 'ESP8266-001',
        ]);

        $response->assertSessionHasErrors('device_id');
    }

    public function test_claim_returns_error_for_unknown_device(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/devices/claim', [
            'device_id' => 'NONEXISTENT',
        ]);

        $response->assertSessionHasErrors('device_id');
    }

    public function test_claim_requires_device_id(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/devices/claim', []);

        $response->assertSessionHasErrors('device_id');
    }

    public function test_guest_cannot_claim_device(): void
    {
        $response = $this->post('/devices/claim', ['device_id' => 'ESP8266-001']);

        $response->assertRedirect('/login');
    }

    public function test_claim_page_is_accessible_to_logged_in_users(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/devices/claim');

        $response->assertOk();
    }
}
