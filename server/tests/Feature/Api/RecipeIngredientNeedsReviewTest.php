<?php

namespace Tests\Feature\Api;

use App\Models\Ingredient;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RecipeIngredientNeedsReviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_recipe_ingredient_defaults_to_not_needing_review(): void
    {
        [$recipe, $ingredient] = $this->createRecipeAndIngredient();

        $recipeIngredient = $recipe->recipeIngredients()->create([
            'ingredient_id' => $ingredient->id,
            'value' => 1,
            'unit' => 'piece',
        ]);

        $this->assertFalse($recipeIngredient->fresh()->needs_review);
        $this->assertDatabaseHas('recipe_ingredients', [
            'id' => $recipeIngredient->id,
            'needs_review' => false,
        ]);
    }

    public function test_recipe_ingredient_can_be_persisted_as_needing_review(): void
    {
        [$recipe, $ingredient] = $this->createRecipeAndIngredient();

        $recipeIngredient = $recipe->recipeIngredients()->create([
            'ingredient_id' => $ingredient->id,
            'needs_review' => true,
        ]);

        $this->assertTrue($recipeIngredient->needs_review);
        $this->assertTrue($recipeIngredient->fresh()->needs_review);
        $this->assertDatabaseHas('recipe_ingredients', [
            'id' => $recipeIngredient->id,
            'needs_review' => true,
        ]);
    }

    public function test_recipe_creation_behavior_and_response_remain_unchanged(): void
    {
        $user = $this->createUser();
        $ingredient = Ingredient::create(['name' => 'Cartof', 'default_unit' => 'piece']);
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/recipes', [
            'name' => 'Cartof copt',
            'ingredients' => [
                [
                    'ingredient_id' => $ingredient->id,
                    'value' => 1,
                    'unit' => 'piece',
                    'raw_text' => 'un cartof',
                ],
            ],
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.ingredients.0.id', $ingredient->id)
            ->assertJsonPath('data.ingredients.0.value', '1.000')
            ->assertJsonPath('data.ingredients.0.unit', 'piece')
            ->assertJsonMissingPath('data.ingredients.0.needs_review');

        $this->assertDatabaseHas('recipe_ingredients', [
            'recipe_id' => $response->json('data.id'),
            'ingredient_id' => $ingredient->id,
            'needs_review' => false,
        ]);
    }

    public function test_cleanup_migration_recalculates_existing_needs_review_flags(): void
    {
        $user = $this->createUser();
        $liter = Ingredient::create(['name' => 'Borș', 'default_unit' => 'liter']);
        $piece = Ingredient::create(['name' => 'Cartof', 'default_unit' => 'piece']);
        $noUnit = Ingredient::create(['name' => 'Sare', 'default_unit' => null]);

        $createRecipeIngredient = function (Ingredient $ingredient, array $attributes) use ($user) {
            $recipe = $user->recipes()->create(['name' => 'Rețetă '.$ingredient->id.' '.count($attributes)]);

            return $recipe->recipeIngredients()->create([
                ...$attributes,
                'ingredient_id' => $ingredient->id,
            ]);
        };

        $matchingLiter = $createRecipeIngredient($liter, [
            'value' => 1,
            'unit' => 'liter',
            'raw_text' => '1 liter borș',
            'needs_review' => true,
        ]);
        $mismatchingLiter = $createRecipeIngredient($liter, [
            'unit' => 'milliliter',
            'needs_review' => false,
        ]);
        $matchingPiece = $createRecipeIngredient($piece, [
            'unit' => 'piece',
            'needs_review' => true,
        ]);
        $noneAgainstNull = $createRecipeIngredient($noUnit, [
            'unit' => 'none',
            'needs_review' => true,
        ]);
        $nullAgainstNull = $createRecipeIngredient($noUnit, [
            'unit' => null,
            'needs_review' => true,
        ]);
        $pieceAgainstNull = $createRecipeIngredient($noUnit, [
            'unit' => 'piece',
            'needs_review' => false,
        ]);

        $this->runNeedsReviewCleanupMigration();

        $this->assertFalse($matchingLiter->fresh()->needs_review);
        $this->assertTrue($mismatchingLiter->fresh()->needs_review);
        $this->assertFalse($matchingPiece->fresh()->needs_review);
        $this->assertFalse($noneAgainstNull->fresh()->needs_review);
        $this->assertFalse($nullAgainstNull->fresh()->needs_review);
        $this->assertTrue($pieceAgainstNull->fresh()->needs_review);

        $this->assertDatabaseHas('recipe_ingredients', [
            'id' => $matchingLiter->id,
            'ingredient_id' => $liter->id,
            'value' => 1,
            'unit' => 'liter',
            'raw_text' => '1 liter borș',
        ]);
    }

    /**
     * @return array{Recipe, Ingredient}
     */
    private function createRecipeAndIngredient(): array
    {
        $user = $this->createUser();

        return [
            $user->recipes()->create(['name' => 'Rețetă']),
            Ingredient::create(['name' => 'Ingredient']),
        ];
    }

    private function createUser(): User
    {
        return User::create([
            'username' => 'daniel',
            'pin' => '1234',
        ]);
    }

    private function runNeedsReviewCleanupMigration(): void
    {
        $migration = require database_path(
            'migrations/2026_10_03_000016_recalculate_recipe_ingredient_needs_review_flags.php'
        );

        $migration->up();
    }
}
