<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Contracts\DeviceInterface;
use App\Models\Device;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class GarageTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function api_garage_trigger_is_rate_limited_after_10_per_minute(): void
    {
        $user = User::factory()->create();
        Device::factory()->create(['user_id' => $user->id]);
        Sanctum::actingAs($user);

        $mock = Mockery::mock(DeviceInterface::class);
        $mock->shouldReceive('triggerServo')->times(10)->andReturn(true);
        $this->app->instance(DeviceInterface::class, $mock);

        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/v1/garage/trigger')->assertOk();
        }

        $this->postJson('/api/v1/garage/trigger')->assertStatus(429);
    }
}
