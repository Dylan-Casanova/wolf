<?php

namespace Tests\Feature;

use App\Contracts\DeviceInterface;
use App\Jobs\TriggerScheduledGeofenceJob;
use App\Models\Device;
use App\Models\GeoFence;
use App\Models\ScheduledGeofenceTrigger;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
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

    public function test_geofence_has_pending_scheduled_trigger_relationship(): void
    {
        $user = User::factory()->create();
        $fence = GeoFence::factory()->create(['user_id' => $user->id]);
        $cancelled = ScheduledGeofenceTrigger::factory()->cancelled()->create(['geo_fence_id' => $fence->id]);
        $pending = ScheduledGeofenceTrigger::factory()->create(['geo_fence_id' => $fence->id]);

        $fresh = $fence->fresh()->load('pendingScheduledTrigger');

        $this->assertNotNull($fresh->pendingScheduledTrigger);
        $this->assertEquals($pending->id, $fresh->pendingScheduledTrigger->id);
    }

    public function test_trigger_job_fires_servo_when_pending(): void
    {
        $user = User::factory()->create();
        Device::factory()->esp8266()->online()->create(['user_id' => $user->id]);
        $fence = GeoFence::factory()->active()->create(['user_id' => $user->id]);
        $trigger = ScheduledGeofenceTrigger::factory()->create(['geo_fence_id' => $fence->id]);

        $mock = Mockery::mock(DeviceInterface::class);
        $mock->shouldReceive('triggerServo')->once()->andReturn(true);
        $this->app->instance(DeviceInterface::class, $mock);

        (new TriggerScheduledGeofenceJob($trigger->id))->handle($mock);

        $this->assertEquals('fired', $trigger->fresh()->status);
        $this->assertFalse($fence->fresh()->is_active);
    }

    public function test_trigger_job_does_not_fire_when_cancelled(): void
    {
        $user = User::factory()->create();
        Device::factory()->esp8266()->online()->create(['user_id' => $user->id]);
        $fence = GeoFence::factory()->active()->create(['user_id' => $user->id]);
        $trigger = ScheduledGeofenceTrigger::factory()->cancelled()->create(['geo_fence_id' => $fence->id]);

        $mock = Mockery::mock(DeviceInterface::class);
        $mock->shouldReceive('triggerServo')->never();
        $this->app->instance(DeviceInterface::class, $mock);

        (new TriggerScheduledGeofenceJob($trigger->id))->handle($mock);

        $this->assertEquals('cancelled', $trigger->fresh()->status);
        $this->assertTrue($fence->fresh()->is_active);
    }

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

    public function test_schedule_trigger_creates_pending_record_and_activates_fence(): void
    {
        Queue::fake();

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

        Queue::assertPushed(TriggerScheduledGeofenceJob::class);
    }

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

    public function test_scheduling_cancels_prior_pending_trigger(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $fence = GeoFence::factory()->create(['user_id' => $user->id]);
        $prior = ScheduledGeofenceTrigger::factory()->create(['geo_fence_id' => $fence->id]);

        $response = $this->actingAs($user)->postJson("/geo-fences/{$fence->id}/schedule-trigger", [
            'minutes' => 30,
            'origin_lat' => 29.5,
            'origin_lng' => -98.5,
        ]);

        $response->assertOk();
        $this->assertEquals('cancelled', $prior->fresh()->status);
    }

    public function test_cancel_scheduled_trigger_marks_cancelled_and_deactivates_fence(): void
    {
        $user = User::factory()->create();
        $fence = GeoFence::factory()->active()->create(['user_id' => $user->id]);
        $trigger = ScheduledGeofenceTrigger::factory()->create(['geo_fence_id' => $fence->id]);

        $response = $this->actingAs($user)->deleteJson("/geo-fences/{$fence->id}/scheduled-trigger");

        $response->assertOk();
        $response->assertJson(['fence' => ['is_active' => false]]);
        $this->assertEquals('cancelled', $trigger->fresh()->status);
        $this->assertFalse($fence->fresh()->is_active);
    }
}
