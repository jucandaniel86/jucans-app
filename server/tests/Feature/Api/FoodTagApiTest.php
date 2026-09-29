<?php

namespace Tests\Feature\Api;

use App\Models\FoodTag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FoodTagApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_list_tags(): void
    {
        $rapid = FoodTag::create(['name' => 'Rapid']);
        FoodTag::create(['name' => 'Ciorbă']);
        $user = $this->createUser();
        $recipe = $user->recipes()->create(['name' => 'Cină']);
        $recipe->tags()->attach($rapid);
        Sanctum::actingAs($user);

        $this->getJson('/api/food-tags')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.name', 'Ciorbă')
            ->assertJsonPath('data.0.recipe_count', 0)
            ->assertJsonPath('data.1.name', 'Rapid')
            ->assertJsonPath('data.1.recipe_count', 1);
    }

    public function test_tag_creation_reuses_an_equivalent_normalized_tag(): void
    {
        $existingTag = FoodTag::create(['name' => 'Ciorbă']);
        Sanctum::actingAs($this->createUser());

        $this->postJson('/api/food-tags', ['name' => ' CIORBA '])
            ->assertOk()
            ->assertJsonPath('data.id', $existingTag->id)
            ->assertJsonPath('data.name', 'Ciorbă');

        $this->assertDatabaseCount('food_tags', 1);
    }

    public function test_tag_can_be_created_with_an_emoji(): void
    {
        Sanctum::actingAs($this->createUser());

        $this->postJson('/api/food-tags', [
            'name' => 'Cină rapidă',
            'emoji' => '🍜',
        ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Cină rapidă')
            ->assertJsonPath('data.normalized_name', 'cina rapida')
            ->assertJsonPath('data.emoji', '🍜');

        $this->assertDatabaseHas('food_tags', [
            'name' => 'Cină rapidă',
            'normalized_name' => 'cina rapida',
            'emoji' => '🍜',
        ]);
    }

    public function test_tag_without_an_emoji_remains_valid(): void
    {
        Sanctum::actingAs($this->createUser());

        $this->postJson('/api/food-tags', ['name' => 'Favorit'])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Favorit')
            ->assertJsonPath('data.emoji', null);
    }

    public function test_tag_emoji_can_be_updated_without_affecting_normalized_name(): void
    {
        $tag = FoodTag::create(['name' => 'De încercat']);
        Sanctum::actingAs($this->createUser());

        $this->patchJson("/api/food-tags/{$tag->id}", ['emoji' => '🧪'])
            ->assertOk()
            ->assertJsonPath('data.emoji', '🧪')
            ->assertJsonPath('data.normalized_name', 'de incercat');
    }

    public function test_tag_endpoints_require_authentication(): void
    {
        $this->getJson('/api/food-tags')->assertUnauthorized();
        $this->postJson('/api/food-tags', ['name' => 'Rapid'])->assertUnauthorized();
        $this->patchJson('/api/food-tags/1', ['emoji' => '🍲'])->assertUnauthorized();
    }

    private function createUser(): User
    {
        return User::create([
            'username' => 'daniel',
            'pin' => '1234',
        ]);
    }
}
