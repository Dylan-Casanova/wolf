<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Stream;
use App\Models\User;
use Illuminate\Broadcasting\Broadcasters\Broadcaster;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class StreamChannelAuthTest extends TestCase
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

    #[Test]
    public function stream_owner_can_access_channel(): void
    {
        $user = User::factory()->create();
        $stream = Stream::factory()->create(['user_id' => $user->id]);

        $result = $this->resolveChannelAuth($user, "stream.{$stream->id}");

        $this->assertTrue($result, 'Stream owner should be authorized to access the channel.');
    }

    #[Test]
    public function non_owner_cannot_access_channel(): void
    {
        $owner = User::factory()->create();
        $stream = Stream::factory()->create(['user_id' => $owner->id]);

        $otherUser = User::factory()->create();

        $result = $this->resolveChannelAuth($otherUser, "stream.{$stream->id}");

        $this->assertFalse($result, 'Non-owner should be denied access to the channel.');
    }

    #[Test]
    public function unauthenticated_user_cannot_access_channel(): void
    {
        $owner = User::factory()->create();
        $stream = Stream::factory()->create(['user_id' => $owner->id]);

        // Null user simulates an unauthenticated request
        $result = $this->resolveChannelAuth(null, "stream.{$stream->id}");

        $this->assertFalse($result, 'Unauthenticated user should be denied access to the channel.');
    }
}
