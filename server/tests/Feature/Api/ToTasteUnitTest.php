<?php

namespace Tests\Feature\Api;

use App\Models\Ingredient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ToTasteUnitTest extends TestCase
{
    use RefreshDatabase;

    public function test_to_taste_is_available_and_saved_without_a_number_on_create_and_update(): void
    {
        Sanctum::actingAs(User::create(['username' => 'daniel', 'pin' => '1234']));
        $this->getJson('/api/config/food')->assertOk()
            ->assertJsonPath('units.to_taste.name', 'După gust');

        $created = $this->postJson('/api/recipes', [
            'name' => 'Supa',
            'ingredients' => [[
                'name' => 'Sare', 'default_unit' => 'to_taste',
                'unit' => 'to_taste', 'value' => 5, 'raw_text' => 'sare dupa gust',
            ]],
        ])->assertCreated()->assertJsonPath('data.ingredients.0.value', null)
            ->assertJsonPath('data.ingredients.0.unit', 'to_taste');
        $id = $created->json('data.id');
        $ingredientId = $created->json('data.ingredients.0.id');
        $this->putJson('/api/recipes/'.$id, [
            'ingredients' => [[
                'ingredient_id' => $ingredientId, 'unit' => 'to_taste', 'value' => 10,
                'raw_text' => 'sare dupa gust',
            ]],
        ])->assertOk()->assertJsonPath('data.ingredients.0.value', null);
        $this->assertDatabaseHas('recipe_ingredients', [
            'recipe_id' => $id, 'ingredient_id' => $ingredientId,
            'unit' => 'to_taste', 'value' => null, 'raw_text' => 'sare dupa gust', 'needs_review' => false,
        ]);
        $this->postJson('/api/shopping-lists/active/recipes/'.$id)->assertCreated()
            ->assertJsonPath('data.items.0.unit', 'to_taste')
            ->assertJsonPath('data.items.0.quantity', null)
            ->assertJsonPath('data.items.0.sources.0.quantity', null);
    }

    public function test_selecting_to_taste_does_not_change_existing_canonical_units_or_bypass_review(): void
    {
        Sanctum::actingAs(User::create(['username' => 'daniel', 'pin' => '1234']));
        $ingredient = Ingredient::create(['name' => 'Piper', 'default_unit' => 'gram']);
        $created = $this->postJson('/api/recipes', [
            'name' => 'Supa',
            'ingredients' => [['ingredient_id' => $ingredient->id, 'unit' => 'to_taste']],
        ])->assertCreated();
        $this->assertSame('gram', $ingredient->fresh()->default_unit);
        $this->assertDatabaseHas('recipe_ingredients', [
            'recipe_id' => $created->json('data.id'), 'ingredient_id' => $ingredient->id, 'needs_review' => true,
        ]);
        $this->postJson('/api/shopping-lists/active/recipes/'.$created->json('data.id'))->assertUnprocessable();
    }
}
