<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_log_in_with_a_valid_pin_and_use_the_token(): void
    {
        $user = User::create([
            'username' => 'daniel',
            'pin' => '1234',
        ]);

        $this->assertNotSame('1234', $user->pin);
        $this->assertTrue(Hash::check('1234', $user->pin));

        $loginResponse = $this->postJson('/api/auth/login', [
            'username' => 'daniel',
            'pin' => '1234',
        ]);

        $loginResponse
            ->assertOk()
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonPath('user.username', 'daniel')
            ->assertJsonPath('user.avatar', null)
            ->assertJsonPath('user.is_admin', false)
            ->assertJsonMissingPath('user.pin');

        $token = $loginResponse->json('token');

        $this->assertIsString($token);
        $this->assertNotEmpty($token);

        $this->withToken($token)
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.username', 'daniel')
            ->assertJsonPath('data.avatar', null)
            ->assertJsonPath('data.is_admin', false)
            ->assertJsonMissingPath('data.pin');

        $this->withToken($token)
            ->postJson('/api/auth/logout')
            ->assertNoContent();

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_login_rejects_an_invalid_pin(): void
    {
        User::create([
            'username' => 'daniel',
            'pin' => '1234',
        ]);

        $this->postJson('/api/auth/login', [
            'username' => 'daniel',
            'pin' => '9999',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('username');
    }

    public function test_me_returns_admin_status_as_a_boolean(): void
    {
        $user = User::create([
            'username' => 'admin',
            'pin' => '1234',
        ]);
        $user->setAttribute('is_admin', true);
        Sanctum::actingAs($user);

        $this->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.is_admin', true);
    }

    public function test_unauthenticated_api_access_is_rejected(): void
    {
        $this->getJson('/api/recipes')->assertUnauthorized();
        $this->getJson('/api/config/food')->assertUnauthorized();
    }
}
