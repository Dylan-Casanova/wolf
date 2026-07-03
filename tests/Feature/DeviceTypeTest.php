<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\DeviceType;
use App\Models\Device;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DeviceTypeTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function enum_has_expected_cases(): void
    {
        $cases = array_map(fn ($case) => $case->value, DeviceType::cases());

        $this->assertEquals(['esp32_cam', 'esp8266'], $cases);
    }

    #[Test]
    public function enum_has_labels(): void
    {
        $this->assertEquals('ESP32-CAM', DeviceType::Esp32Cam->label());
        $this->assertEquals('ESP8266', DeviceType::Esp8266->label());
    }

    #[Test]
    public function enum_has_values_array(): void
    {
        $values = DeviceType::values();

        $this->assertEquals(['esp32_cam', 'esp8266'], $values);
    }

    #[Test]
    public function enum_has_options_for_forms(): void
    {
        $options = DeviceType::options();

        $this->assertEquals([
            ['value' => 'esp32_cam', 'label' => 'ESP32-CAM'],
            ['value' => 'esp8266', 'label' => 'ESP8266'],
        ], $options);
    }

    #[Test]
    public function store_device_validates_type_against_enum(): void
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

    #[Test]
    public function store_device_accepts_valid_enum_type(): void
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

    #[Test]
    public function dashboard_passes_devices_with_type(): void
    {
        $user = User::factory()->create();
        Device::factory()->esp8266()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertInertia(fn ($page) => $page
            ->component('Dashboard')
            ->has('devices', 1)
            ->where('devices.0.type', 'esp8266')
        );
    }

    #[Test]
    public function dashboard_passes_empty_devices_without_device(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertInertia(fn ($page) => $page
            ->component('Dashboard')
            ->has('devices', 0)
        );
    }
}
