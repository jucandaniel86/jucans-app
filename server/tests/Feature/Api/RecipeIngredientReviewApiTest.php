<?php

namespace Tests\Feature\Api;

use App\Models\Ingredient;
use App\Models\RecipeIngredient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RecipeIngredientReviewApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_endpoint_returns_only_review_rows_with_recipe_ingredient_and_stored_values(): void
    {
        $admin = $this->createUser('admin', true);
        $recipe = $admin->recipes()->create(['name' => 'Tocăniță']);
        $ingredient = Ingredient::create(['name' => 'Suc de roșii', 'default_unit' => 'liter']);
        $review = $recipe->recipeIngredients()->create([
            'ingredient_id' => $ingredient->id,
            'value' => 750,
            'unit' => 'milliliter',
            'raw_text' => '750 ml suc de rosii',
            'needs_review' => true,
        ]);
        $otherIngredient = Ingredient::create(['name' => 'Sare', 'default_unit' => null]);
        $recipe->recipeIngredients()->create([
            'ingredient_id' => $otherIngredient->id,
            'unit' => null,
            'needs_review' => false,
        ]);
        Sanctum::actingAs($admin);

        $this->getJson('/api/admin/recipe-ingredient-reviews')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $review->id)
            ->assertJsonPath('data.0.recipe.id', $recipe->id)
            ->assertJsonPath('data.0.recipe.name', 'Tocăniță')
            ->assertJsonPath('data.0.ingredient.id', $ingredient->id)
            ->assertJsonPath('data.0.ingredient.name', 'Suc de roșii')
            ->assertJsonPath('data.0.ingredient.default_unit', 'liter')
            ->assertJsonPath('data.0.value', '750.000')
            ->assertJsonPath('data.0.unit', 'milliliter')
            ->assertJsonPath('data.0.raw_text', '750 ml suc de rosii')
            ->assertJsonPath('data.0.needs_review', true)
            ->assertJsonPath('meta.total', 1);
    }

    public function test_search_matches_normalized_ingredient_recipe_name_and_raw_text(): void
    {
        $admin = $this->createUser('admin', true);
        $tomatoReview = $this->createReview($admin, 'Cină rapidă', 'Suc de roșii', 'din conservă');
        $soupReview = $this->createReview($admin, 'Supă de legume', 'Morcov', 'doi morcovi');
        $rawReview = $this->createReview($admin, 'Prânz', 'Piper', 'proaspăt măcinat');
        Sanctum::actingAs($admin);

        $this->getJson('/api/admin/recipe-ingredient-reviews?search=rosii')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $tomatoReview->id);

        $this->getJson('/api/admin/recipe-ingredient-reviews?search='.urlencode('Supă'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $soupReview->id);

        $this->getJson('/api/admin/recipe-ingredient-reviews?search='.urlencode('măcinat'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $rawReview->id);
    }

    public function test_pagination_total_counts_all_matching_review_rows(): void
    {
        $admin = $this->createUser('admin', true);
        $this->createReview($admin, 'Rețeta 1', 'Ingredient 1', 'primul');
        $this->createReview($admin, 'Rețeta 2', 'Ingredient 2', 'al doilea');
        $this->createReview($admin, 'Rețeta 3', 'Ingredient 3', 'al treilea', false);
        Sanctum::actingAs($admin);

        $this->getJson('/api/admin/recipe-ingredient-reviews?per_page=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('meta.total', 2);
    }

    public function test_non_admin_and_unauthenticated_users_cannot_access_review_queue(): void
    {
        $this->getJson('/api/admin/recipe-ingredient-reviews')->assertUnauthorized();

        Sanctum::actingAs($this->createUser('member', false));

        $this->getJson('/api/admin/recipe-ingredient-reviews')->assertForbidden();
    }

    public function test_correcting_stored_value_and_unit_clears_review_without_automatic_conversion(): void
    {
        $user = $this->createUser('daniel');
        $recipe = $user->recipes()->create(['name' => 'Tocăniță']);
        $ingredient = Ingredient::create(['name' => 'Suc de roșii', 'default_unit' => 'liter']);
        $recipe->recipeIngredients()->create([
            'ingredient_id' => $ingredient->id,
            'value' => 750,
            'unit' => 'milliliter',
            'needs_review' => true,
        ]);
        Sanctum::actingAs($user);

        $this->patchJson("/api/recipes/{$recipe->id}", [
            'ingredients' => [[
                'ingredient_id' => $ingredient->id,
                'value' => 0.75,
                'unit' => 'liter',
                'raw_text' => '750 ml suc de rosii',
            ]],
        ])->assertOk();

        $saved = $recipe->recipeIngredients()->firstOrFail();
        $this->assertSame('0.750', $saved->value);
        $this->assertSame('liter', $saved->unit);
        $this->assertFalse($saved->needs_review);
    }

    public function test_saving_a_mismatching_unit_sets_review_and_preserves_quantity_exactly(): void
    {
        $user = $this->createUser('daniel');
        $recipe = $user->recipes()->create(['name' => 'Tocăniță']);
        $ingredient = Ingredient::create(['name' => 'Suc de roșii', 'default_unit' => 'liter']);
        $recipe->recipeIngredients()->create([
            'ingredient_id' => $ingredient->id,
            'value' => 0.75,
            'unit' => 'liter',
            'needs_review' => false,
        ]);
        Sanctum::actingAs($user);

        $this->patchJson("/api/recipes/{$recipe->id}", [
            'ingredients' => [[
                'ingredient_id' => $ingredient->id,
                'value' => 750,
                'unit' => 'milliliter',
                'raw_text' => '750 ml suc de rosii',
            ]],
        ])->assertOk();

        $saved = $recipe->recipeIngredients()->firstOrFail();
        $this->assertSame('750.000', $saved->value);
        $this->assertSame('milliliter', $saved->unit);
        $this->assertTrue($saved->needs_review);
    }

    private function createReview(
        User $creator,
        string $recipeName,
        string $ingredientName,
        string $rawText,
        bool $needsReview = true
    ): RecipeIngredient {
        $recipe = $creator->recipes()->create(['name' => $recipeName]);
        $ingredient = Ingredient::create(['name' => $ingredientName, 'default_unit' => 'piece']);

        return $recipe->recipeIngredients()->create([
            'ingredient_id' => $ingredient->id,
            'value' => 1,
            'unit' => 'none',
            'raw_text' => $rawText,
            'needs_review' => $needsReview,
        ]);
    }

    private function createUser(string $username, bool $isAdmin = false): User
    {
        return User::create([
            'username' => $username,
            'pin' => '1234',
            'is_admin' => $isAdmin,
        ]);
    }
}
