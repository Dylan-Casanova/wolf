<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeviceRegisterTest extends TestCase
{
    use RefreshDatabase;

    public function test_device_can_self_register(): void
    {
        $response = $this->postJson('/api/device/register', [
            'device_id' => 'ESP8266-001',
            'type' => 'esp8266',
            'name' => 'ESP8266-001',
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure(['device_id', 'token']);

        $this->assertDatabaseHas('devices', [
            'device_id' => 'ESP8266-001',
            'type' => 'esp8266',
            'name' => 'ESP8266-001',
            'user_id' => null,
        ]);
    }

    public function test_duplicate_registration_returns_existing_token(): void
    {
        $response1 = $this->postJson('/api/device/register', [
            'device_id' => 'ESP8266-001',
            'type' => 'esp8266',
            'name' => 'ESP8266-001',
        ]);

        $token1 = $response1->json('token');

        $response2 = $this->postJson('/api/device/register', [
            'device_id' => 'ESP8266-001',
            'type' => 'esp8266',
            'name' => 'ESP8266-001',
        ]);

        $response2->assertOk();
        $token2 = $response2->json('token');

        $this->assertEquals($token1, $token2);
        $this->assertDatabaseCount('devices', 1);
    }

    public function test_registration_validates_device_id_format(): void
    {
        $response = $this->postJson('/api/device/register', [
            'device_id' => 'invalid-format',
            'type' => 'esp8266',
            'name' => 'My Device',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('device_id');
    }

    public function test_registration_requires_all_fields(): void
    {
        $response = $this->postJson('/api/device/register', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['device_id', 'type', 'name']);
    }

    public function test_registration_validates_device_type(): void
    {
        $response = $this->postJson('/api/device/register', [
            'device_id' => 'ESP8266-001',
            'type' => 'invalid_type',
            'name' => 'ESP8266-001',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('type');
    }

    public function test_registration_is_rate_limited(): void
    {
        for ($i = 0; $i < 11; $i++) {
            $response = $this->postJson('/api/device/register', [
                'device_id' => "ESP8266-{$i}",
                'type' => 'esp8266',
                'name' => "ESP8266-{$i}",
            ]);
        }

        $response->assertStatus(429);
    }
}
