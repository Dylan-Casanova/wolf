<?php

namespace Tests\Feature;

use App\Enums\DeviceType;
use App\Models\Device;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeviceTypeTest extends TestCase
{
    use RefreshDatabase;

    public function test_enum_has_expected_cases(): void
    {
        $cases = array_map(fn ($case) => $case->value, DeviceType::cases());

        $this->assertEquals(['esp32_cam', 'esp8266'], $cases);
    }

    public function test_enum_has_labels(): void
    {
        $this->assertEquals('ESP32-CAM', DeviceType::Esp32Cam->label());
        $this->assertEquals('ESP8266', DeviceType::Esp8266->label());
    }

    public function test_enum_has_values_array(): void
    {
        $values = DeviceType::values();

        $this->assertEquals(['esp32_cam', 'esp8266'], $values);
    }

    public function test_enum_has_options_for_forms(): void
    {
        $options = DeviceType::options();

        $this->assertEquals([
            ['value' => 'esp32_cam', 'label' => 'ESP32-CAM'],
            ['value' => 'esp8266', 'label' => 'ESP8266'],
        ], $options);
    }

    public function test_store_device_validates_type_against_enum(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $user = User::factory()->create();

        $response = $this->actingAs($admin)->post(route('devices.store'), [
            'name' => 'Test Device',
            'device_id' => 'test-001',
            'user_id' => $user->id,
            'type' => 'invalid_type',
        ]);

        $response->assertSessionHasErrors('type');
    }

    public function test_store_device_accepts_valid_enum_type(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $user = User::factory()->create();

        $response = $this->actingAs($admin)->post(route('devices.store'), [
            'name' => 'Test Device',
            'device_id' => 'test-001',
            'user_id' => $user->id,
            'type' => 'esp8266',
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertDatabaseHas('devices', ['device_id' => 'test-001', 'type' => 'esp8266']);
    }

    public function test_dashboard_passes_device_type(): void
    {
        $user = User::factory()->create();
        Device::factory()->esp8266()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertInertia(fn ($page) => $page
            ->component('Dashboard')
            ->has('deviceType')
            ->where('deviceType', 'esp8266')
        );
    }

    public function test_dashboard_passes_null_device_type_without_device(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertInertia(fn ($page) => $page
            ->component('Dashboard')
            ->where('deviceType', null)
        );
    }
}
