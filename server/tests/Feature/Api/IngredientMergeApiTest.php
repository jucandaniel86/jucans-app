<?php

namespace Tests\Feature\Api;

use App\Models\Ingredient;
use App\Models\ShoppingCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class IngredientMergeApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_merge_ingredients_with_same_units_and_preserve_data(): void
    {
        $user = $this->createUser(true);
        $sourceCategory = ShoppingCategory::create(['name' => 'Sursă', 'emoji' => 'S', 'sort_order' => 1]);
        $targetCategory = ShoppingCategory::create(['name' => 'Țintă', 'emoji' => 'T', 'sort_order' => 2]);
        $source = Ingredient::create([
            'name' => 'Tomată',
            'default_unit' => 'piece',
            'shopping_category_id' => $sourceCategory->id,
            'is_shoppable' => false,
        ]);
        $target = Ingredient::create([
            'name' => 'Roșie',
            'default_unit' => 'piece',
            'shopping_category_id' => $targetCategory->id,
            'is_shoppable' => true,
        ]);
        $sourceAlias = $source->aliases()->create(['alias' => 'Pătlăgea']);
        $recipe = $user->recipes()->create(['name' => 'Salată']);
        $recipeIngredient = $recipe->recipeIngredients()->create([
            'ingredient_id' => $source->id,
            'value' => 2.750,
            'unit' => 'piece',
            'raw_text' => '2 3/4 tomate coapte',
            'needs_review' => false,
        ]);
        Sanctum::actingAs($user);

        $this->postJson("/api/admin/ingredients/{$source->id}/merge", [
            'target_ingredient_id' => $target->id,
        ])
            ->assertOk()
            ->assertJsonPath('target.id', $target->id)
            ->assertJsonPath('target.name', 'Roșie')
            ->assertJsonPath('target.default_unit', 'piece')
            ->assertJsonPath('target.shopping_category.id', $targetCategory->id)
            ->assertJsonPath('target.is_shoppable', true)
            ->assertJsonPath('merged_source.id', $source->id)
            ->assertJsonPath('merged_source.name', 'Tomată')
            ->assertJsonPath('result.recipe_ingredient_count', 1)
            ->assertJsonPath('result.alias_count', 2)
            ->assertJsonPath('result.needs_review_count', 0);

        $movedRow = $recipeIngredient->fresh();
        $this->assertSame($target->id, $movedRow->ingredient_id);
        $this->assertSame('2.750', $movedRow->value);
        $this->assertSame('piece', $movedRow->unit);
        $this->assertSame('2 3/4 tomate coapte', $movedRow->raw_text);
        $this->assertFalse($movedRow->needs_review);
        $this->assertDatabaseMissing('ingredients', ['id' => $source->id]);
        $this->assertDatabaseHas('ingredient_aliases', [
            'id' => $sourceAlias->id,
            'ingredient_id' => $target->id,
            'normalized_alias' => 'patlagea',
        ]);
        $this->assertDatabaseHas('ingredient_aliases', [
            'ingredient_id' => $target->id,
            'alias' => 'Tomată',
            'normalized_alias' => 'tomata',
        ]);

        $unchangedTarget = $target->fresh();
        $this->assertSame('Roșie', $unchangedTarget->name);
        $this->assertSame('piece', $unchangedTarget->default_unit);
        $this->assertSame($targetCategory->id, $unchangedTarget->shopping_category_id);
        $this->assertTrue($unchangedTarget->is_shoppable);
    }

    public function test_merge_marks_only_mismatching_rows_and_never_clears_review(): void
    {
        $user = $this->createUser(true);
        $source = Ingredient::create(['name' => 'Făină', 'default_unit' => 'gram']);
        $target = Ingredient::create(['name' => 'Făină albă', 'default_unit' => 'kilogram']);
        $matching = $this->attachToRecipe($user, $source, 'Pâine', 'kilogram', false);
        $mismatching = $this->attachToRecipe($user, $source, 'Clătite', 'gram', false);
        $alreadyFlagged = $this->attachToRecipe($user, $source, 'Prăjitură', 'kilogram', true);
        Sanctum::actingAs($user);

        $this->postJson("/api/admin/ingredients/{$source->id}/merge", [
            'target_ingredient_id' => $target->id,
        ])
            ->assertOk()
            ->assertJsonPath('result.recipe_ingredient_count', 3)
            ->assertJsonPath('result.needs_review_count', 1);

        $this->assertFalse($matching->fresh()->needs_review);
        $this->assertTrue($mismatching->fresh()->needs_review);
        $this->assertSame('gram', $mismatching->fresh()->unit);
        $this->assertTrue($alreadyFlagged->fresh()->needs_review);
    }

    public function test_merge_handles_nullable_units_like_preview(): void
    {
        $user = $this->createUser(true);
        $source = Ingredient::create(['name' => 'Apă', 'default_unit' => 'liter']);
        $target = Ingredient::create(['name' => 'Apă plată', 'default_unit' => null]);
        $matching = $this->attachToRecipe($user, $source, 'Supă', null, false);
        $mismatching = $this->attachToRecipe($user, $source, 'Aluat', 'milliliter', false);
        Sanctum::actingAs($user);

        $this->postJson("/api/admin/ingredients/{$source->id}/merge", [
            'target_ingredient_id' => $target->id,
        ])
            ->assertOk()
            ->assertJsonPath('result.needs_review_count', 1);

        $this->assertFalse($matching->fresh()->needs_review);
        $this->assertTrue($mismatching->fresh()->needs_review);
    }

    public function test_source_name_is_not_duplicated_when_it_is_already_a_target_alias(): void
    {
        $user = $this->createUser(true);
        $source = Ingredient::create(['name' => 'Tomate']);
        $target = Ingredient::create(['name' => 'Roșii']);
        $existingAlias = $target->aliases()->create(['alias' => 'TOMATE']);
        Sanctum::actingAs($user);

        $this->postJson("/api/admin/ingredients/{$source->id}/merge", [
            'target_ingredient_id' => $target->id,
        ])
            ->assertOk()
            ->assertJsonPath('result.alias_count', 0);

        $this->assertSame(1, $target->aliases()->where('normalized_alias', 'tomate')->count());
        $this->assertDatabaseHas('ingredient_aliases', [
            'id' => $existingAlias->id,
            'ingredient_id' => $target->id,
        ]);
    }

    public function test_source_and_target_must_be_different(): void
    {
        $source = Ingredient::create(['name' => 'Sare']);
        Sanctum::actingAs($this->createUser(true));

        $this->postJson("/api/admin/ingredients/{$source->id}/merge", [
            'target_ingredient_id' => $source->id,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('target_ingredient_id');

        $this->assertDatabaseHas('ingredients', ['id' => $source->id]);
    }

    public function test_recipe_conflict_blocks_merge_without_writes(): void
    {
        $user = $this->createUser(true);
        $source = Ingredient::create(['name' => 'Roșie']);
        $target = Ingredient::create(['name' => 'Roșii']);
        $recipe = $user->recipes()->create(['name' => 'Salată de vară']);
        $recipe->recipeIngredients()->createMany([
            ['ingredient_id' => $source->id, 'value' => 1],
            ['ingredient_id' => $target->id, 'value' => 2],
        ]);
        $source->aliases()->create(['alias' => 'Tomată']);
        Sanctum::actingAs($user);
        $before = $this->databaseSnapshot();

        $this->postJson("/api/admin/ingredients/{$source->id}/merge", [
            'target_ingredient_id' => $target->id,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('target_ingredient_id')
            ->assertJsonPath('conflicts.0.recipe_id', $recipe->id)
            ->assertJsonPath('conflicts.0.recipe_name', 'Salată de vară');

        $this->assertSame($before, $this->databaseSnapshot());
    }

    public function test_alias_name_collision_blocks_merge_without_writes(): void
    {
        $user = $this->createUser(true);
        $source = Ingredient::create(['name' => 'Roșie']);
        $target = Ingredient::create(['name' => 'Tomată']);
        $aliasOwner = Ingredient::create(['name' => 'Legumă']);
        $alias = $aliasOwner->aliases()->create(['alias' => 'ROSIE']);
        $this->attachToRecipe($user, $source, 'Salată', null, false);
        Sanctum::actingAs($user);
        $before = $this->databaseSnapshot();

        $this->postJson("/api/admin/ingredients/{$source->id}/merge", [
            'target_ingredient_id' => $target->id,
        ])
            ->assertUnprocessable()
            ->assertJsonPath('conflicts', [])
            ->assertJsonPath('alias_name_collision.has_collision', true)
            ->assertJsonPath('alias_name_collision.existing_alias.id', $alias->id);

        $this->assertSame($before, $this->databaseSnapshot());
    }

    public function test_failure_during_merge_rolls_back_all_prior_writes(): void
    {
        $user = $this->createUser(true);
        $source = Ingredient::create(['name' => 'Tomată', 'default_unit' => 'gram']);
        $target = Ingredient::create(['name' => 'Roșie', 'default_unit' => 'kilogram']);
        $source->aliases()->create(['alias' => 'Pătlăgea']);
        $this->attachToRecipe($user, $source, 'Salată', 'gram', false);
        Sanctum::actingAs($user);
        $before = $this->databaseSnapshot();

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER fail_ingredient_alias_move
            BEFORE UPDATE OF ingredient_id ON ingredient_aliases
            BEGIN
                SELECT RAISE(FAIL, 'forced alias failure');
            END
        SQL);

        $this->postJson("/api/admin/ingredients/{$source->id}/merge", [
            'target_ingredient_id' => $target->id,
        ])->assertInternalServerError();

        $this->assertSame($before, $this->databaseSnapshot());
    }

    public function test_non_admin_cannot_merge(): void
    {
        $source = Ingredient::create(['name' => 'Sare']);
        $target = Ingredient::create(['name' => 'Sare fină']);
        Sanctum::actingAs($this->createUser(false));

        $this->postJson("/api/admin/ingredients/{$source->id}/merge", [
            'target_ingredient_id' => $target->id,
        ])->assertForbidden();

        $this->assertDatabaseHas('ingredients', ['id' => $source->id]);
    }

    private function attachToRecipe(
        User $user,
        Ingredient $ingredient,
        string $recipeName,
        ?string $unit,
        bool $needsReview
    ): \App\Models\RecipeIngredient {
        $recipe = $user->recipes()->create(['name' => $recipeName]);

        return $recipe->recipeIngredients()->create([
            'ingredient_id' => $ingredient->id,
            'value' => 1.500,
            'unit' => $unit,
            'raw_text' => "1.5 {$ingredient->name}",
            'needs_review' => $needsReview,
        ]);
    }

    /**
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function databaseSnapshot(): array
    {
        return collect(['ingredients', 'ingredient_aliases', 'recipe_ingredients', 'recipes'])
            ->mapWithKeys(fn (string $table) => [
                $table => DB::table($table)
                    ->orderBy('id')
                    ->get()
                    ->map(fn (object $row) => (array) $row)
                    ->all(),
            ])
            ->all();
    }

    private function createUser(bool $isAdmin): User
    {
        return User::create([
            'username' => $isAdmin ? 'admin' : 'member',
            'pin' => '1234',
            'is_admin' => $isAdmin,
        ]);
    }
}
