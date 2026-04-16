<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\DeviceCapture;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CaptureHistoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_user_is_redirected(): void
    {
        $response = $this->get('/captures');

        $response->assertRedirect('/login');
    }

    public function test_user_can_access_capture_history(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/captures');

        $response->assertOk();
    }

    public function test_user_only_sees_their_own_captures(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $device = Device::factory()->create(['user_id' => $user->id]);
        $otherDevice = Device::factory()->create(['user_id' => $otherUser->id]);

        DeviceCapture::factory()->create(['user_id' => $user->id, 'device_id' => $device->id]);
        DeviceCapture::factory()->create(['user_id' => $otherUser->id, 'device_id' => $otherDevice->id]);

        $response = $this->actingAs($user)->get('/captures');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Captures/History')
            ->has('captures.data', 1)
        );
    }

    public function test_admin_sees_all_captures(): void
    {
        $admin = User::factory()->admin()->create();
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        $device1 = Device::factory()->create(['user_id' => $user1->id]);
        $device2 = Device::factory()->create(['user_id' => $user2->id]);

        DeviceCapture::factory()->create(['user_id' => $user1->id, 'device_id' => $device1->id]);
        DeviceCapture::factory()->create(['user_id' => $user2->id, 'device_id' => $device2->id]);

        $response = $this->actingAs($admin)->get('/captures');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Captures/History')
            ->has('captures.data', 2)
        );
    }

    public function test_is_admin_flag_is_passed_to_page(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->get('/captures');

        $response->assertInertia(fn ($page) => $page
            ->where('isAdmin', true)
        );
    }
}
