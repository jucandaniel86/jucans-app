<?php

namespace Tests\Feature\Api;

use App\Models\Ingredient;
use App\Models\Recipe;
use App\Models\ShoppingCategory;
use App\Models\ShoppingList;
use App\Models\ShoppingListItem;
use App\Models\ShoppingListItemSource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

class ShoppingListRecipeApiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::create(['username' => 'shopper', 'pin' => '1234']);
    }

    public function test_add_creates_active_list_attachment_item_and_source_with_canonical_snapshots(): void
    {
        $category = ShoppingCategory::create(['name' => 'Dairy', 'sort_order' => 1]);
        $ingredient = $this->ingredient(['shopping_category_id' => $category->id]);
        $recipe = $this->recipe($ingredient, '200.125');
        Sanctum::actingAs($this->user);

        $response = $this->postJson($this->endpoint($recipe))->assertCreated()
            ->assertJsonPath('data.status', 'open')
            ->assertJsonPath('data.visibility', 'private')
            ->assertJsonPath('data.created_by', $this->user->id)
            ->assertJsonPath('data.recipe.id', $recipe->id)
            ->assertJsonPath('data.already_present', false)
            ->assertJsonPath('data.recipes_count', 1)
            ->assertJsonPath('data.items_count', 1)
            ->assertJsonPath('data.unchecked_items_count', 1)
            ->assertJsonPath('data.items.0.ingredient_id', $ingredient->id)
            ->assertJsonPath('data.items.0.name', 'Parmesan')
            ->assertJsonPath('data.items.0.unit', 'gram')
            ->assertJsonPath('data.items.0.calculated_quantity', '200.125')
            ->assertJsonPath('data.items.0.quantity', '200.125')
            ->assertJsonPath('data.items.0.quantity_overridden', false)
            ->assertJsonPath('data.items.0.is_checked', false)
            ->assertJsonPath('data.items.0.shopping_category.id', $category->id)
            ->assertJsonPath('data.items.0.sources.0.recipe_id', $recipe->id)
            ->assertJsonPath('data.items.0.sources.0.quantity', '200.125')
            ->assertJsonPath('data.items.0.sources.0.unit', 'gram');

        $this->assertDatabaseHas('shopping_list_recipes', [
            'shopping_list_id' => $response->json('data.id'), 'recipe_id' => $recipe->id, 'added_by' => $this->user->id,
        ]);
        $this->assertDatabaseMissing('shopping_list_users', ['shopping_list_id' => $response->json('data.id'), 'user_id' => $this->user->id]);
        $this->assertDatabaseCount('shopping_list_item_sources', 1);
    }

    public function test_existing_shared_open_list_is_used_and_closed_and_inaccessible_lists_are_ignored(): void
    {
        $owner = User::create(['username' => 'owner', 'pin' => '1234']);
        $shared = ShoppingList::create(['created_by' => $owner->id, 'status' => 'open', 'visibility' => 'shared']);
        $shared->users()->attach($this->user->id);
        ShoppingList::create(['created_by' => $this->user->id, 'status' => 'closed']);
        ShoppingList::create(['created_by' => $owner->id, 'status' => 'open']);
        Sanctum::actingAs($this->user);

        $this->postJson($this->endpoint($this->recipe($this->ingredient(), 200)))
            ->assertCreated()->assertJsonPath('data.id', $shared->id);
        $this->assertDatabaseCount('shopping_lists', 3);
        $this->assertDatabaseHas('shopping_list_recipes', ['shopping_list_id' => $shared->id, 'added_by' => $this->user->id]);
    }

    public function test_duplicate_is_idempotent_even_if_recipe_later_requires_review(): void
    {
        $recipe = $this->recipe($this->ingredient(), 200);
        Sanctum::actingAs($this->user);
        $first = $this->postJson($this->endpoint($recipe))->assertCreated();
        $recipe->recipeIngredients()->update(['needs_review' => true]);

        $this->postJson($this->endpoint($recipe))->assertOk()
            ->assertJsonPath('data.id', $first->json('data.id'))
            ->assertJsonPath('data.already_present', true)
            ->assertJsonPath('data.items.0.quantity', '200.000');
        $this->assertDatabaseCount('shopping_lists', 1);
        $this->assertDatabaseCount('shopping_list_recipes', 1);
        $this->assertDatabaseCount('shopping_list_items', 1);
        $this->assertDatabaseCount('shopping_list_item_sources', 1);
    }

    public function test_non_shoppable_ingredients_are_ignored_but_recipe_is_attached(): void
    {
        $recipe = $this->recipe($this->ingredient(['is_shoppable' => false]), 200, 'liter');
        Sanctum::actingAs($this->user);
        $this->postJson($this->endpoint($recipe))->assertCreated()
            ->assertJsonPath('data.items', [])
            ->assertJsonPath('data.recipes_count', 1);
        $this->assertDatabaseCount('shopping_list_items', 0);
        $this->assertDatabaseCount('shopping_list_item_sources', 0);
    }

    public function test_quantities_sum_by_canonical_id_and_existing_snapshots_and_checked_state_are_preserved(): void
    {
        $ingredient = $this->ingredient();
        $first = $this->recipe($ingredient, '200.125');
        $second = $this->recipe($ingredient, '150.250');
        Sanctum::actingAs($this->user);
        $this->postJson($this->endpoint($first))->assertCreated();
        $item = ShoppingListItem::firstOrFail();
        $item->update(['is_checked' => true, 'name' => 'Original snapshot']);
        $category = ShoppingCategory::create(['name' => 'New category', 'sort_order' => 1]);
        $ingredient->update(['name' => 'Renamed Parmesan', 'shopping_category_id' => $category->id]);

        $this->postJson($this->endpoint($second))->assertCreated()
            ->assertJsonPath('data.items.0.id', $item->id)
            ->assertJsonPath('data.items.0.name', 'Original snapshot')
            ->assertJsonPath('data.items.0.calculated_quantity', '350.375')
            ->assertJsonPath('data.items.0.quantity', '350.375')
            ->assertJsonPath('data.items.0.is_checked', true)
            ->assertJsonPath('data.items.0.shopping_category', null)
            ->assertJsonCount(2, 'data.items.0.sources');
        $this->assertDatabaseCount('shopping_list_items', 1);
        $this->assertDatabaseCount('shopping_list_item_sources', 2);
    }

    public function test_similar_names_with_different_ingredient_ids_remain_separate(): void
    {
        Sanctum::actingAs($this->user);
        $this->postJson($this->endpoint($this->recipe($this->ingredient(), 200)))->assertCreated();
        $this->postJson($this->endpoint($this->recipe($this->ingredient(['name' => 'Parmesan grated']), 150)))->assertCreated()
            ->assertJsonPath('data.items.0.quantity', '150.000');
        $this->assertDatabaseCount('shopping_list_items', 2);
    }

    public function test_mixed_recipe_only_generates_sources_for_shoppable_ingredients(): void
    {
        $included = $this->ingredient();
        $recipe = $this->recipe($included, 200);
        $ignored = $this->ingredient(['name' => 'Water', 'is_shoppable' => false]);
        $recipe->recipeIngredients()->create(['ingredient_id' => $ignored->id, 'value' => 1, 'unit' => 'gram']);
        Sanctum::actingAs($this->user);

        $this->postJson($this->endpoint($recipe))->assertCreated()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.ingredient_id', $included->id);
        $this->assertDatabaseCount('shopping_list_recipes', 1);
        $this->assertDatabaseCount('shopping_list_item_sources', 1);
        $this->assertDatabaseMissing('shopping_list_items', ['ingredient_id' => $ignored->id]);
    }

    public function test_existing_source_with_a_stale_unit_blocks_further_aggregation(): void
    {
        $ingredient = $this->ingredient();
        Sanctum::actingAs($this->user);
        $this->postJson($this->endpoint($this->recipe($ingredient, 200)))->assertCreated();
        ShoppingListItemSource::firstOrFail()->update(['unit' => 'kilogram']);

        $this->postJson($this->endpoint($this->recipe($ingredient, 150)))->assertUnprocessable();
        $this->assertDatabaseCount('shopping_list_recipes', 1);
        $this->assertDatabaseCount('shopping_list_item_sources', 1);
        $this->assertSame('200.000', ShoppingListItem::firstOrFail()->calculated_quantity);
    }

    public function test_null_contribution_makes_total_and_unoverridden_quantity_unknown_in_either_order(): void
    {
        Sanctum::actingAs($this->user);
        foreach ([[5, null], [null, 5], [null, null]] as $index => [$first, $second]) {
            $ingredient = $this->ingredient(['name' => 'Thyme '.$index]);
            $this->postJson($this->endpoint($this->recipe($ingredient, $first)))->assertCreated()
                ->assertJsonPath('data.items.0.quantity', $first === null ? null : '5.000');
            $this->postJson($this->endpoint($this->recipe($ingredient, $second)))->assertCreated()
                ->assertJsonPath('data.items.0.calculated_quantity', null)
                ->assertJsonPath('data.items.0.quantity', null)
                ->assertJsonCount(2, 'data.items.0.sources');
        }
    }

    public function test_user_override_is_preserved_for_numeric_and_null_totals(): void
    {
        $ingredient = $this->ingredient();
        Sanctum::actingAs($this->user);
        $this->postJson($this->endpoint($this->recipe($ingredient, 750)))->assertCreated();
        ShoppingListItem::firstOrFail()->update(['quantity' => '1000.125', 'quantity_overridden' => true]);

        $this->postJson($this->endpoint($this->recipe($ingredient, 200)))->assertCreated()
            ->assertJsonPath('data.items.0.calculated_quantity', '950.000')
            ->assertJsonPath('data.items.0.quantity', '1000.125')
            ->assertJsonPath('data.items.0.quantity_overridden', true);
        $this->postJson($this->endpoint($this->recipe($ingredient, null)))->assertCreated()
            ->assertJsonPath('data.items.0.calculated_quantity', null)
            ->assertJsonPath('data.items.0.quantity', '1000.125');
    }

    public function test_null_empty_and_none_units_match_null_canonical_unit(): void
    {
        $ingredient = $this->ingredient(['default_unit' => null]);
        Sanctum::actingAs($this->user);
        foreach ([null, '', 'none'] as $unit) {
            $this->postJson($this->endpoint($this->recipe($ingredient, null, $unit)))->assertCreated()
                ->assertJsonPath('data.items.0.unit', null)
                ->assertJsonPath('data.items.0.calculated_quantity', null)
                ->assertJsonPath('data.items.0.sources.0.unit', null);
        }
        $this->assertDatabaseCount('shopping_list_items', 1);
        $this->assertDatabaseCount('shopping_list_item_sources', 3);
    }

    public function test_needs_review_blocks_entire_recipe_including_non_shoppable_rows_before_list_creation(): void
    {
        $recipe = $this->recipe($this->ingredient(), 200);
        $blocked = $recipe->recipeIngredients()->create([
            'ingredient_id' => $this->ingredient(['name' => 'Water', 'is_shoppable' => false])->id,
            'value' => 1, 'unit' => 'gram', 'needs_review' => true,
        ]);
        Sanctum::actingAs($this->user);
        $this->postJson($this->endpoint($recipe))->assertUnprocessable()
            ->assertJsonValidationErrors('recipe_ingredients.'.$blocked->id.'.needs_review');
        $this->assertNoShoppingWrites();
    }

    public function test_defensive_mismatch_rejects_without_conversion_or_partial_writes(): void
    {
        $recipe = $this->recipe($this->ingredient(), 200);
        $blocked = $recipe->recipeIngredients()->create([
            'ingredient_id' => $this->ingredient(['name' => 'Milk', 'default_unit' => 'liter'])->id,
            'value' => 500, 'unit' => 'milliliter', 'needs_review' => false,
        ]);
        Sanctum::actingAs($this->user);
        $this->postJson($this->endpoint($recipe))->assertUnprocessable()
            ->assertJsonValidationErrors('recipe_ingredients.'.$blocked->id.'.unit');
        $this->assertNoShoppingWrites();
        $this->assertDatabaseHas('recipe_ingredients', ['id' => $blocked->id, 'value' => 500, 'unit' => 'milliliter']);
    }

    public function test_exception_during_source_creation_rolls_back_new_list_membership_attachment_and_items(): void
    {
        $recipe = $this->recipe($this->ingredient(), 200);
        Sanctum::actingAs($this->user);
        $this->withoutExceptionHandling();
        ShoppingListItemSource::creating(function (): void {
            throw new RuntimeException('Simulated source write failure');
        });

        try {
            $this->postJson($this->endpoint($recipe));
            $this->fail('Expected a source write failure');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated source write failure', $exception->getMessage());
        } finally {
            ShoppingListItemSource::flushEventListeners();
        }
        $this->assertNoShoppingWrites();
    }

    public function test_failure_after_an_existing_item_update_rolls_back_quantities_sources_and_attachment(): void
    {
        $ingredient = $this->ingredient();
        Sanctum::actingAs($this->user);
        $this->postJson($this->endpoint($this->recipe($ingredient, 200)))->assertCreated();
        $recipe = $this->recipe($ingredient, 150);
        $other = $this->ingredient(['name' => 'Milk', 'default_unit' => 'liter']);
        $recipe->recipeIngredients()->create(['ingredient_id' => $other->id, 'value' => 1, 'unit' => 'liter']);
        ShoppingList::firstOrFail()->items()->create(['ingredient_id' => $other->id, 'name' => 'Old milk', 'unit' => 'milliliter']);

        $this->postJson($this->endpoint($recipe))->assertUnprocessable();
        $this->assertDatabaseCount('shopping_list_recipes', 1);
        $this->assertDatabaseCount('shopping_list_item_sources', 1);
        $this->assertSame('200.000', ShoppingListItem::where('ingredient_id', $ingredient->id)->firstOrFail()->quantity);
    }

    public function test_endpoint_requires_authentication_and_unknown_recipe_returns_not_found(): void
    {
        $this->postJson($this->endpoint($this->recipe($this->ingredient(), 200)))->assertUnauthorized();
        Sanctum::actingAs($this->user);
        $this->postJson('/api/shopping-lists/active/recipes/999999')->assertNotFound();
        $this->assertNoShoppingWrites();
    }

    private function ingredient(array $attributes = []): Ingredient
    {
        return Ingredient::create(['name' => 'Parmesan', 'default_unit' => 'gram', 'is_shoppable' => true, ...$attributes]);
    }

    private function recipe(Ingredient $ingredient, int|string|null $value, ?string $unit = 'gram'): Recipe
    {
        $recipe = $this->user->recipes()->create(['name' => 'Recipe']);
        $recipe->recipeIngredients()->create(['ingredient_id' => $ingredient->id, 'value' => $value, 'unit' => $unit, 'needs_review' => false]);

        return $recipe;
    }

    private function endpoint(Recipe $recipe): string
    {
        return '/api/shopping-lists/active/recipes/'.$recipe->id;
    }

    private function assertNoShoppingWrites(): void
    {
        foreach (['shopping_lists', 'shopping_list_users', 'shopping_list_recipes', 'shopping_list_items', 'shopping_list_item_sources'] as $table) {
            $this->assertSame(0, DB::table($table)->count(), $table);
        }
    }
}
