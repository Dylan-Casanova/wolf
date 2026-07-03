<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function login_returns_token_and_user_on_valid_credentials(): void
    {
        $user = User::factory()->create([
            'email' => 'rider@example.com',
            'password' => Hash::make('correct-horse-battery'),
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'rider@example.com',
            'password' => 'correct-horse-battery',
            'device_name' => "Dylan's iPhone",
        ]);

        $response->assertOk();
        $response->assertJsonStructure(['token', 'user' => ['id', 'email']]);
        $this->assertEquals($user->id, $response->json('user.id'));
        $this->assertNotEmpty($response->json('token'));
        $this->assertDatabaseHas('personal_access_tokens', [
            'tokenable_id' => $user->id,
            'name' => "Dylan's iPhone",
        ]);
    }

    #[Test]
    public function login_rejects_wrong_password(): void
    {
        User::factory()->create([
            'email' => 'rider@example.com',
            'password' => Hash::make('correct-horse-battery'),
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'rider@example.com',
            'password' => 'wrong-password',
            'device_name' => 'phone',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('email');
    }

    #[Test]
    public function login_rejects_nonexistent_email(): void
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'nobody@example.com',
            'password' => 'anything',
            'device_name' => 'phone',
        ]);

        $response->assertStatus(422);
    }

    #[Test]
    public function login_requires_device_name(): void
    {
        User::factory()->create(['email' => 'rider@example.com']);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'rider@example.com',
            'password' => 'whatever',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('device_name');
    }

    #[Test]
    public function register_creates_user_and_returns_token(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Dylan',
            'email' => 'new@example.com',
            'phone_number' => '+15551234567',
            'password' => 'super-secret-pw',
            'password_confirmation' => 'super-secret-pw',
            'device_name' => "Dylan's iPhone",
        ]);

        $response->assertCreated();
        $response->assertJsonStructure(['token', 'user' => ['id', 'email']]);
        $this->assertDatabaseHas('users', ['email' => 'new@example.com']);
        $this->assertNotEmpty($response->json('token'));
    }

    #[Test]
    public function register_rejects_duplicate_email(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Dylan',
            'email' => 'taken@example.com',
            'password' => 'super-secret-pw',
            'password_confirmation' => 'super-secret-pw',
            'device_name' => 'phone',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('email');
    }

    #[Test]
    public function register_requires_password_confirmation(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Dylan',
            'email' => 'new@example.com',
            'password' => 'super-secret-pw',
            'password_confirmation' => 'different-pw',
            'device_name' => 'phone',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('password');
    }

    #[Test]
    public function register_rejects_short_password(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Dylan',
            'email' => 'new@example.com',
            'password' => 'short',
            'password_confirmation' => 'short',
            'device_name' => 'phone',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('password');
    }

    #[Test]
    public function logout_revokes_current_token(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('phone')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/auth/logout');

        $response->assertNoContent();
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    #[Test]
    public function logout_requires_authentication(): void
    {
        $response = $this->postJson('/api/v1/auth/logout');
        $response->assertStatus(401);
    }

    #[Test]
    public function user_endpoint_returns_authenticated_user(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('phone')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/auth/user');

        $response->assertOk();
        $response->assertJson(['id' => $user->id, 'email' => $user->email]);
    }

    #[Test]
    public function user_endpoint_requires_authentication(): void
    {
        $response = $this->getJson('/api/v1/auth/user');
        $response->assertStatus(401);
    }

    #[Test]
    public function token_grants_access_to_existing_geofence_endpoint(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('phone')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/geo-fences');

        $response->assertOk();
        $response->assertJson([]);
    }
}
