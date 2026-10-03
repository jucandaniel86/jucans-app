<?php

namespace Tests\Feature\Api;

use App\Models\Ingredient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class IngredientMergePreviewApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_preview_a_same_unit_merge_without_mutating_data(): void
    {
        $user = $this->createUser(true);
        $source = Ingredient::create(['name' => 'Tomată', 'default_unit' => 'piece']);
        $target = Ingredient::create(['name' => 'Roșie', 'default_unit' => 'piece']);
        $source->aliases()->createMany([
            ['alias' => 'Tomată roșie'],
            ['alias' => 'Pătlăgea'],
        ]);
        $recipe = $user->recipes()->create(['name' => 'Salată']);
        $recipeIngredient = $recipe->recipeIngredients()->create([
            'ingredient_id' => $source->id,
            'value' => 2,
            'unit' => 'piece',
            'needs_review' => true,
        ]);
        Sanctum::actingAs($user);
        $before = $this->databaseSnapshot();

        $this->postJson("/api/admin/ingredients/{$source->id}/merge-preview", [
            'target_ingredient_id' => $target->id,
        ])
            ->assertOk()
            ->assertExactJson([
                'source' => [
                    'id' => $source->id,
                    'name' => 'Tomată',
                    'default_unit' => 'piece',
                ],
                'target' => [
                    'id' => $target->id,
                    'name' => 'Roșie',
                    'default_unit' => 'piece',
                ],
                'impact' => [
                    'recipe_count' => 1,
                    'recipe_ingredient_count' => 1,
                    'alias_count' => 2,
                    'needs_review_count' => 0,
                ],
                'unit_mismatch' => false,
                'conflicts' => [],
                'alias_name_collision' => [
                    'has_collision' => false,
                    'canonical_ingredient' => null,
                    'existing_alias' => null,
                ],
                'can_merge' => true,
            ]);

        $this->assertSame($before, $this->databaseSnapshot());
        $this->assertTrue($recipeIngredient->fresh()->needs_review);
    }

    public function test_preview_calculates_review_per_row_and_handles_nullable_units(): void
    {
        $user = $this->createUser(true);
        $source = Ingredient::create(['name' => 'Făină', 'default_unit' => 'gram']);
        $target = Ingredient::create(['name' => 'Făină albă', 'default_unit' => null]);
        $this->attachToRecipe($user, $source, 'Pâine', null);
        $this->attachToRecipe($user, $source, 'Prăjitură', 'gram');
        $this->attachToRecipe($user, $source, 'Clătite', 'tablespoon');
        Sanctum::actingAs($user);

        $this->postJson("/api/admin/ingredients/{$source->id}/merge-preview", [
            'target_ingredient_id' => $target->id,
        ])
            ->assertOk()
            ->assertJsonPath('impact.recipe_count', 3)
            ->assertJsonPath('impact.recipe_ingredient_count', 3)
            ->assertJsonPath('impact.needs_review_count', 2)
            ->assertJsonPath('unit_mismatch', true)
            ->assertJsonPath('can_merge', true);
    }

    public function test_source_and_target_must_be_different(): void
    {
        $source = Ingredient::create(['name' => 'Sare']);
        Sanctum::actingAs($this->createUser(true));

        $this->postJson("/api/admin/ingredients/{$source->id}/merge-preview", [
            'target_ingredient_id' => $source->id,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('target_ingredient_id');
    }

    public function test_target_is_required_and_must_exist(): void
    {
        $source = Ingredient::create(['name' => 'Sare']);
        Sanctum::actingAs($this->createUser(true));

        $this->postJson("/api/admin/ingredients/{$source->id}/merge-preview", [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('target_ingredient_id');

        $this->postJson("/api/admin/ingredients/{$source->id}/merge-preview", [
            'target_ingredient_id' => 999999,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('target_ingredient_id');
    }

    public function test_preview_reports_recipes_that_already_contain_the_target(): void
    {
        $user = $this->createUser(true);
        $source = Ingredient::create(['name' => 'Roșie']);
        $target = Ingredient::create(['name' => 'Roșii']);
        $conflictingRecipe = $user->recipes()->create(['name' => 'Salată de vară']);
        $conflictingRecipe->recipeIngredients()->createMany([
            ['ingredient_id' => $source->id],
            ['ingredient_id' => $target->id],
        ]);
        $this->attachToRecipe($user, $source, 'Supă', null);
        Sanctum::actingAs($user);

        $this->postJson("/api/admin/ingredients/{$source->id}/merge-preview", [
            'target_ingredient_id' => $target->id,
        ])
            ->assertOk()
            ->assertJsonPath('impact.recipe_count', 2)
            ->assertJsonPath('impact.recipe_ingredient_count', 2)
            ->assertJsonCount(1, 'conflicts')
            ->assertJsonPath('conflicts.0.recipe_id', $conflictingRecipe->id)
            ->assertJsonPath('conflicts.0.recipe_name', 'Salată de vară')
            ->assertJsonPath('can_merge', false);
    }

    public function test_preview_reports_a_source_name_alias_collision(): void
    {
        $user = $this->createUser(true);
        $source = Ingredient::create(['name' => 'Roșie']);
        $target = Ingredient::create(['name' => 'Tomată']);
        $aliasOwner = Ingredient::create(['name' => 'Legumă']);
        $alias = $aliasOwner->aliases()->create(['alias' => 'ROSIE']);
        Sanctum::actingAs($user);

        $this->postJson("/api/admin/ingredients/{$source->id}/merge-preview", [
            'target_ingredient_id' => $target->id,
        ])
            ->assertOk()
            ->assertJsonPath('alias_name_collision.has_collision', true)
            ->assertJsonPath('alias_name_collision.canonical_ingredient', null)
            ->assertJsonPath('alias_name_collision.existing_alias.id', $alias->id)
            ->assertJsonPath('alias_name_collision.existing_alias.alias', 'ROSIE')
            ->assertJsonPath('alias_name_collision.existing_alias.ingredient_id', $aliasOwner->id)
            ->assertJsonPath('alias_name_collision.existing_alias.ingredient_name', 'Legumă')
            ->assertJsonPath('can_merge', false);
    }

    public function test_non_admin_cannot_preview_a_merge(): void
    {
        $source = Ingredient::create(['name' => 'Sare']);
        $target = Ingredient::create(['name' => 'Sare fină']);
        Sanctum::actingAs($this->createUser(false));

        $this->postJson("/api/admin/ingredients/{$source->id}/merge-preview", [
            'target_ingredient_id' => $target->id,
        ])->assertForbidden();
    }

    private function attachToRecipe(
        User $user,
        Ingredient $ingredient,
        string $recipeName,
        ?string $unit
    ): void {
        $recipe = $user->recipes()->create(['name' => $recipeName]);
        $recipe->recipeIngredients()->create([
            'ingredient_id' => $ingredient->id,
            'unit' => $unit,
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
