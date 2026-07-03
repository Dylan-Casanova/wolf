<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ErrorShapeTest extends TestCase
{
    use RefreshDatabase;

    /**
     * These tests deliberately use bare `->get()` / `->post()` rather than
     * `->getJson()` / `->postJson()` — omitting `Accept: application/json`
     * is the whole point. If they still return JSON, `shouldRenderJsonWhen()`
     * is doing its job.
     */
    #[Test]
    public function unauthenticated_api_request_returns_401_json_without_accept_header(): void
    {
        $response = $this->get('/api/v1/auth/user');

        $response->assertStatus(401);
        $response->assertJsonStructure(['message']);
    }

    #[Test]
    public function validation_failure_on_api_route_returns_422_json_without_accept_header(): void
    {
        // POST to register with an empty body — required fields missing.
        $response = $this->post('/api/v1/auth/register', []);

        $response->assertStatus(422);
        $response->assertJsonStructure(['message', 'errors']);
    }

    #[Test]
    public function not_found_route_on_api_surface_returns_404_json_without_accept_header(): void
    {
        $response = $this->get('/api/v1/this-route-does-not-exist');

        $response->assertStatus(404);
        $response->assertJsonStructure(['message']);
    }
}
