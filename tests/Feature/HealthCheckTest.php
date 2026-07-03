<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class HealthCheckTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function health_endpoint_returns_healthy_status(): void
    {
        Redis::shouldReceive('ping')->once()->andReturn('PONG');

        $response = $this->getJson('/health');

        $response->assertOk()
            ->assertJson([
                'status' => 'healthy',
            ])
            ->assertJsonStructure([
                'status',
                'checks' => [
                    'database',
                    'redis',
                ],
            ]);
    }

    #[Test]
    public function health_endpoint_is_accessible_without_auth(): void
    {
        Redis::shouldReceive('ping')->once()->andReturn('PONG');

        $response = $this->getJson('/health');

        $response->assertOk();
    }
}
