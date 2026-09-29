<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UserAvatarApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_update_only_their_own_avatar(): void
    {
        $user = $this->createUser('daniel');
        $otherUser = $this->createUser('maria');
        Sanctum::actingAs($user);

        $this->patchJson('/api/auth/avatar', ['avatar' => 'avatar-03'])
            ->assertOk()
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.avatar', 'avatar-03')
            ->assertJsonMissingPath('data.pin');

        $this->assertDatabaseHas('users', ['id' => $user->id, 'avatar' => 'avatar-03']);
        $this->assertDatabaseHas('users', ['id' => $otherUser->id, 'avatar' => null]);
    }

    public function test_invalid_avatar_identifier_is_rejected(): void
    {
        $user = $this->createUser('daniel');
        Sanctum::actingAs($user);

        $this->patchJson('/api/auth/avatar', ['avatar' => 'avatar-999'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('avatar');

        $this->assertNull($user->fresh()->avatar);
    }

    public function test_avatar_update_requires_authentication(): void
    {
        $this->patchJson('/api/auth/avatar', ['avatar' => 'avatar-01'])
            ->assertUnauthorized();
    }

    public function test_me_returns_avatar_without_exposing_pin(): void
    {
        $user = $this->createUser('daniel');
        $user->update(['avatar' => 'avatar-08']);
        Sanctum::actingAs($user);

        $this->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.avatar', 'avatar-08')
            ->assertJsonMissingPath('data.pin');
    }

    private function createUser(string $username): User
    {
        return User::create([
            'username' => $username,
            'pin' => '1234',
        ]);
    }
}
