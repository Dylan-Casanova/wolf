<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Contracts\DeviceInterface;
use App\Models\Device;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class GarageControllerTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function authenticated_user_with_device_can_trigger(): void
    {
        $user = User::factory()->create();
        $device = Device::factory()->create(['user_id' => $user->id]);

        $mock = Mockery::mock(DeviceInterface::class);
        $mock->shouldReceive('triggerServo')
            ->once()
            ->with(Mockery::on(fn ($d) => $d->id === $device->id))
            ->andReturn(true);
        $this->app->instance(DeviceInterface::class, $mock);

        $response = $this->actingAs($user)->post('/garage/trigger');

        $response->assertOk();
        $response->assertJson(['message' => 'Command sent.']);
    }

    #[Test]
    public function user_without_device_gets_422(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/garage/trigger');

        $response->assertStatus(422);
    }

    #[Test]
    public function unauthenticated_gets_redirect(): void
    {
        $response = $this->post('/garage/trigger');

        $response->assertRedirect('/login');
    }

    #[Test]
    public function garage_trigger_is_rate_limited_after_10_per_minute(): void
    {
        $user = User::factory()->create();
        Device::factory()->create(['user_id' => $user->id]);

        $mock = Mockery::mock(DeviceInterface::class);
        $mock->shouldReceive('triggerServo')->times(10)->andReturn(true);
        $this->app->instance(DeviceInterface::class, $mock);

        for ($i = 0; $i < 10; $i++) {
            $this->actingAs($user)->postJson('/garage/trigger')->assertOk();
        }

        $this->actingAs($user)->postJson('/garage/trigger')->assertStatus(429);
    }
}
