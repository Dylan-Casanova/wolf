<?php

namespace Tests\Feature;

use App\Contracts\DeviceInterface;
use App\Models\Device;
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

        $response = $this->actingAs($user)->postJson('/geo-fences', [
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

        $response = $this->actingAs($user)->postJson('/geo-fences', [
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

        $response = $this->actingAs($user)->putJson("/geo-fences/{$fence->id}", [
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

        $response = $this->actingAs($user)->putJson("/geo-fences/{$fence->id}", [
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

        $response = $this->actingAs($user)->deleteJson("/geo-fences/{$fence->id}");

        $response->assertOk();
        $this->assertDatabaseMissing('geo_fences', ['id' => $fence->id]);
    }

    public function test_user_can_toggle_geofence(): void
    {
        $user = User::factory()->create();
        $fence = GeoFence::factory()->create(['user_id' => $user->id, 'is_active' => false]);

        $response = $this->actingAs($user)->postJson("/geo-fences/{$fence->id}/toggle");

        $response->assertOk();
        $response->assertJson(['is_active' => true]);

        $response = $this->actingAs($user)->postJson("/geo-fences/{$fence->id}/toggle");

        $response->assertOk();
        $response->assertJson(['is_active' => false]);
    }

    public function test_check_inside_geofence_triggers_servo(): void
    {
        $user = User::factory()->create();
        Device::factory()->esp8266()->online()->create(['user_id' => $user->id]);
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

        $response = $this->actingAs($user)->postJson("/geo-fences/{$fence->id}/check", [
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

        $response = $this->actingAs($user)->postJson("/geo-fences/{$fence->id}/check", [
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

        $response = $this->actingAs($user)->postJson("/geo-fences/{$fence->id}/check", [
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

        $response = $this->actingAs($user)->postJson("/geo-fences/{$fence->id}/check", [
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

        $response = $this->actingAs($user)->getJson('/geo-fences');

        $response->assertOk();
        $response->assertJsonFragment(['id' => $fence->id]);
    }

    public function test_guest_cannot_access_geofence_endpoints(): void
    {
        $response = $this->getJson('/geo-fences');
        $response->assertUnauthorized();
    }
}
