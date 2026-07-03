<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Device;
use App\Models\User;
use Illuminate\Broadcasting\Broadcasters\Broadcaster;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeviceChannelAuthTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Resolve the registered channel callback for the given channel pattern
     * and invoke it with the given user and parameters.
     */
    private function resolveChannelAuth(?User $user, string $channelName): bool
    {
        // Unauthenticated users are always denied before the callback is invoked.
        if ($user === null) {
            return false;
        }

        /** @var Broadcaster $broadcaster */
        $broadcaster = app(BroadcastManager::class)->connection();

        $channels = $broadcaster->getChannels();

        foreach ($channels as $pattern => $callback) {
            // Convert {param} pattern to a regex
            $regex = preg_replace('/\{(\w+)\}/', '(\d+|\w+)', $pattern);
            $regex = '#^'.$regex.'$#';

            if (! preg_match($regex, $channelName, $matches)) {
                continue;
            }

            $params = array_slice($matches, 1); // drop full match

            // Invoke the callback with user + extracted params
            $result = $callback($user, ...$params);

            return (bool) $result;
        }

        return false;
    }

    public function test_device_owner_can_access_device_channel(): void
    {
        $user = User::factory()->create();
        $device = Device::factory()->create(['user_id' => $user->id]);

        $result = $this->resolveChannelAuth($user, "device.{$device->id}");

        $this->assertTrue($result, 'Device owner should be authorized to access the channel.');
    }

    public function test_non_owner_cannot_access_device_channel(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $device = Device::factory()->create(['user_id' => $owner->id]);

        $result = $this->resolveChannelAuth($other, "device.{$device->id}");

        $this->assertFalse($result, 'Non-owner should be denied access to the device channel.');
    }

    public function test_nonexistent_device_returns_false(): void
    {
        $user = User::factory()->create();

        $result = $this->resolveChannelAuth($user, 'device.99999');

        $this->assertFalse($result, 'Non-existent device should return false.');
    }
}
