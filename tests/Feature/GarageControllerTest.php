<?php

namespace Tests\Feature;

use App\Contracts\DeviceInterface;
use App\Models\Device;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class GarageControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_with_device_can_trigger(): void
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

    public function test_user_without_device_gets_422(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/garage/trigger');

        $response->assertStatus(422);
    }

    public function test_unauthenticated_gets_redirect(): void
    {
        $response = $this->post('/garage/trigger');

        $response->assertRedirect('/login');
    }
}
