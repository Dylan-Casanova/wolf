<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HealthCheckTest extends TestCase
{
    use RefreshDatabase;

    public function test_health_endpoint_returns_healthy_status(): void
    {
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

    public function test_health_endpoint_is_accessible_without_auth(): void
    {
        $response = $this->getJson('/health');

        $response->assertOk();
    }
}
