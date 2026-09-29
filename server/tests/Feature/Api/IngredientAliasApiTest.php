<?php

namespace Tests\Feature\Api;

use App\Models\Ingredient;
use App\Models\IngredientAlias;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class IngredientAliasApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_search_canonical_ingredients(): void
    {
        $salt = Ingredient::create(['name' => 'Sare', 'default_unit' => 'gram']);
        Ingredient::create(['name' => 'Pătrunjel', 'default_unit' => 'bunch']);
        Sanctum::actingAs($this->createUser());

        $this->getJson('/api/ingredients/search?query=sar')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $salt->id)
            ->assertJsonPath('data.0.name', 'Sare');
    }

    public function test_explicit_alias_association_is_normalized_and_resolves_in_future(): void
    {
        $parsley = Ingredient::create(['name' => 'Pătrunjel', 'default_unit' => 'bunch']);
        Sanctum::actingAs($this->createUser());

        $this->postJson('/api/ingredients/aliases', [
            'alias' => '  Frunze de PĂTRUNJEL ',
            'ingredient_id' => $parsley->id,
        ])
            ->assertCreated()
            ->assertJsonPath('data.normalized_alias', 'frunze de patrunjel')
            ->assertJsonPath('data.ingredient.id', $parsley->id);

        $this->assertDatabaseHas('ingredient_aliases', [
            'ingredient_id' => $parsley->id,
            'alias' => 'Frunze de PĂTRUNJEL',
            'normalized_alias' => 'frunze de patrunjel',
        ]);

        $this->postJson('/api/ingredients/resolve', [
            'ingredients' => ['2 linguri frunze de patrunjel'],
        ])
            ->assertOk()
            ->assertJsonPath('data.0.status', 'matched')
            ->assertJsonPath('data.0.ingredient.id', $parsley->id)
            ->assertJsonPath('data.0.raw_text', '2 linguri frunze de patrunjel')
            ->assertJsonPath('data.0.parsed.value', 2)
            ->assertJsonPath('data.0.parsed.unit', 'tablespoon');
    }

    public function test_same_alias_cannot_point_to_two_ingredients(): void
    {
        $salt = Ingredient::create(['name' => 'Sare']);
        $seaSalt = Ingredient::create(['name' => 'Sare de mare']);
        IngredientAlias::create([
            'ingredient_id' => $salt->id,
            'alias' => 'Un praf sărat',
        ]);
        Sanctum::actingAs($this->createUser());

        $this->postJson('/api/ingredients/aliases', [
            'alias' => 'un praf sarat',
            'ingredient_id' => $seaSalt->id,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('alias');

        $this->assertDatabaseCount('ingredient_aliases', 1);
    }

    public function test_canonical_match_takes_precedence_over_an_alias(): void
    {
        $canonical = Ingredient::create(['name' => 'Sare']);
        $other = Ingredient::create(['name' => 'Sare afumată']);
        IngredientAlias::create([
            'ingredient_id' => $other->id,
            'alias' => 'Sare',
        ]);
        Sanctum::actingAs($this->createUser());

        $this->postJson('/api/ingredients/resolve', ['ingredients' => ['sare']])
            ->assertOk()
            ->assertJsonPath('data.0.ingredient.id', $canonical->id);
    }

    public function test_alias_and_search_endpoints_require_authentication(): void
    {
        $ingredient = Ingredient::create(['name' => 'Sare']);

        $this->getJson('/api/ingredients/search?query=sare')->assertUnauthorized();
        $this->postJson('/api/ingredients/aliases', [
            'alias' => 'praf de sare',
            'ingredient_id' => $ingredient->id,
        ])->assertUnauthorized();
    }

    private function createUser(): User
    {
        return User::create([
            'username' => 'daniel',
            'pin' => '1234',
        ]);
    }
}
