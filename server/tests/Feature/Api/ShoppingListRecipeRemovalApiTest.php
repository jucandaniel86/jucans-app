<?php

namespace Tests\Feature\Api;

use App\Models\Ingredient;
use App\Models\Recipe;
use App\Models\ShoppingList;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ShoppingListRecipeRemovalApiTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = $this->user('owner');
        Sanctum::actingAs($this->owner);
    }

    public function test_removal_detaches_recipe_deletes_its_sources_and_last_source_item_and_returns_complete_state(): void
    {
        $ingredient = $this->ingredient();
        $recipe = $this->recipe($ingredient, 2);
        $list = $this->add($recipe);
        $item = $list->items()->firstOrFail();
        $source = $item->sources()->firstOrFail();
        $rawRows = DB::table('recipe_ingredients')->get()->toArray();

        $this->deleteJson($this->endpoint($list, $recipe))->assertOk()
            ->assertJsonPath('data.id', $list->id)->assertJsonPath('data.status', 'open')
            ->assertJsonPath('data.creator.id', $this->owner->id)
            ->assertJsonPath('data.recipes_count', 0)->assertJsonPath('data.items_count', 0)
            ->assertJsonPath('data.unchecked_items_count', 0)
            ->assertJsonCount(0, 'data.items')->assertJsonCount(0, 'data.recipes');
        $this->assertDatabaseMissing('shopping_list_recipes', ['shopping_list_id' => $list->id, 'recipe_id' => $recipe->id]);
        $this->assertDatabaseMissing('shopping_list_item_sources', ['id' => $source->id]);
        $this->assertDatabaseMissing('shopping_list_items', ['id' => $item->id]);
        $this->assertDatabaseHas('recipes', ['id' => $recipe->id]);
        $this->assertDatabaseHas('ingredients', ['id' => $ingredient->id, 'default_unit' => 'piece']);
        $this->assertEquals($rawRows, DB::table('recipe_ingredients')->get()->toArray());
    }

    public function test_checked_and_overridden_item_is_still_deleted_when_its_last_recipe_source_is_removed(): void
    {
        $recipe = $this->recipe($this->ingredient(), 2);
        $list = $this->add($recipe);
        $item = $list->items()->firstOrFail();
        $item->update(['is_checked' => true, 'quantity' => 9, 'quantity_overridden' => true]);
        $this->deleteJson($this->endpoint($list, $recipe))->assertOk()->assertJsonCount(0, 'data.items');
        $this->assertDatabaseMissing('shopping_list_items', ['id' => $item->id]);
    }

    public function test_shared_ingredient_recalculates_from_one_remaining_source_and_preserves_checked_state(): void
    {
        $ingredient = $this->ingredient();
        $first = $this->recipe($ingredient, 2);
        $second = $this->recipe($ingredient, 1);
        $list = $this->add($first);
        $this->add($second);
        $item = $list->items()->firstOrFail();
        $this->assertSame('3.000', $item->quantity);
        $item->update(['is_checked' => true]);

        $this->deleteJson($this->endpoint($list, $first))->assertOk()
            ->assertJsonPath('data.recipes_count', 1)->assertJsonPath('data.items_count', 1)
            ->assertJsonPath('data.unchecked_items_count', 0)
            ->assertJsonPath('data.recipes.0.id', $second->id)
            ->assertJsonPath('data.items.0.id', $item->id)
            ->assertJsonPath('data.items.0.calculated_quantity', '1.000')
            ->assertJsonPath('data.items.0.quantity', '1.000')
            ->assertJsonPath('data.items.0.quantity_overridden', false)
            ->assertJsonPath('data.items.0.is_checked', true)
            ->assertJsonCount(1, 'data.items.0.sources')
            ->assertJsonPath('data.items.0.sources.0.recipe_id', $second->id);
        $this->assertDatabaseMissing('shopping_list_item_sources', ['shopping_list_item_id' => $item->id, 'recipe_id' => $first->id]);
    }

    public function test_multiple_remaining_numeric_sources_are_summed_with_existing_decimal_precision(): void
    {
        $ingredient = $this->ingredient();
        $removed = $this->recipe($ingredient, '2.125');
        $list = $this->add($removed);
        $this->add($this->recipe($ingredient, '1.250'));
        $this->add($this->recipe($ingredient, '3.500'));
        $this->deleteJson($this->endpoint($list, $removed))->assertOk()
            ->assertJsonPath('data.items.0.calculated_quantity', '4.750')
            ->assertJsonPath('data.items.0.quantity', '4.750')
            ->assertJsonCount(2, 'data.items.0.sources')->assertJsonCount(2, 'data.recipes');
    }

    public function test_null_contributions_keep_existing_aggregation_semantics_and_removing_the_null_restores_a_total(): void
    {
        foreach ([[null, [2], '2.000'], [2, [null], null], [2, [1, null], null]] as $index => [$removedQuantity, $remainingQuantities, $expected]) {
            $ingredient = $this->ingredient('Onion '.$index);
            $removed = $this->recipe($ingredient, $removedQuantity);
            $list = $this->add($removed);
            foreach ($remainingQuantities as $quantity) {
                $this->add($this->recipe($ingredient, $quantity));
            }
            $item = $list->items()->where('ingredient_id', $ingredient->id)->firstOrFail();
            $this->deleteJson($this->endpoint($list, $removed))->assertOk();
            $this->assertSame($expected, $item->refresh()->calculated_quantity);
            $this->assertSame($expected, $item->quantity);
            $this->assertSame(count($remainingQuantities), $item->sources()->count());
        }
    }

    public function test_manual_quantity_override_including_null_is_preserved_when_calculated_quantity_changes(): void
    {
        foreach ([[1, 9], [null, 9], [1, null]] as $index => [$remainingQuantity, $override]) {
            $ingredient = $this->ingredient('Onion '.$index);
            $removed = $this->recipe($ingredient, 2);
            $list = $this->add($removed);
            $this->add($this->recipe($ingredient, $remainingQuantity));
            $item = $list->items()->where('ingredient_id', $ingredient->id)->firstOrFail();
            $item->update(['quantity' => $override, 'quantity_overridden' => true, 'is_checked' => true]);
            $this->deleteJson($this->endpoint($list, $removed))->assertOk();
            $item->refresh();
            $this->assertSame($remainingQuantity === null ? null : '1.000', $item->calculated_quantity);
            $this->assertSame($override === null ? null : '9.000', $item->quantity);
            $this->assertTrue($item->quantity_overridden);
            $this->assertTrue($item->is_checked);
        }
    }

    public function test_manual_items_and_similarly_named_unaffected_generated_items_are_untouched(): void
    {
        $removed = $this->recipe($this->ingredient(), 2);
        $list = $this->add($removed);
        $remaining = $this->recipe($this->ingredient('Onion sliced'), 5);
        $this->add($remaining);
        $unaffected = $list->items()->where('name', 'Onion sliced')->firstOrFail();
        $unaffectedSnapshot = (array) DB::table('shopping_list_items')->find($unaffected->id);
        $manual = $list->items()->create([
            'ingredient_id' => null, 'name' => 'Onion', 'calculated_quantity' => null,
            'quantity' => 7, 'unit' => 'buc', 'quantity_overridden' => true, 'is_checked' => true,
        ]);
        $nameOnly = $list->items()->create(['name' => 'Servetele', 'quantity_overridden' => true]);
        $snapshots = [(array) DB::table('shopping_list_items')->find($manual->id), (array) DB::table('shopping_list_items')->find($nameOnly->id)];

        $this->deleteJson($this->endpoint($list, $removed))->assertOk()->assertJsonPath('data.items_count', 3);
        $this->assertSame($snapshots[0], (array) DB::table('shopping_list_items')->find($manual->id));
        $this->assertSame($snapshots[1], (array) DB::table('shopping_list_items')->find($nameOnly->id));
        $this->assertSame($unaffectedSnapshot, (array) DB::table('shopping_list_items')->find($unaffected->id));
        $this->assertSame(0, $manual->sources()->count());
        $this->assertSame(1, $unaffected->sources()->count());
    }

    public function test_removal_is_explicitly_scoped_to_one_list_even_when_multiple_open_lists_are_accessible(): void
    {
        $recipe = $this->recipe($this->ingredient(), 2);
        $list = $this->add($recipe);
        $other = ShoppingList::create(['created_by' => $this->owner->id, 'status' => 'open', 'visibility' => 'private']);
        $other->recipes()->attach($recipe->id, ['added_by' => $this->owner->id]);
        $item = $list->items()->firstOrFail()->replicate();
        $item->shopping_list_id = $other->id;
        $item->save();
        $source = $item->sources()->create(['recipe_id' => $recipe->id, 'quantity' => 2, 'unit' => 'piece']);
        $snapshot = (array) DB::table('shopping_list_items')->find($item->id);
        $sourceSnapshot = (array) DB::table('shopping_list_item_sources')->find($source->id);

        $this->getJson('/api/shopping-lists/active')->assertConflict();
        $this->deleteJson($this->endpoint($list, $recipe))->assertOk()->assertJsonPath('data.id', $list->id);
        $this->assertDatabaseHas('shopping_list_recipes', ['shopping_list_id' => $other->id, 'recipe_id' => $recipe->id]);
        $this->assertSame($snapshot, (array) DB::table('shopping_list_items')->find($item->id));
        $this->assertSame($sourceSnapshot, (array) DB::table('shopping_list_item_sources')->find($source->id));
    }

    public function test_shared_member_can_remove_recipe_even_with_their_own_open_list(): void
    {
        $recipe = $this->recipe($this->ingredient(), 2);
        $list = $this->add($recipe);
        $list->update(['visibility' => 'shared']);
        $member = $this->user('member');
        $list->users()->attach($member->id);
        $own = ShoppingList::create(['created_by' => $member->id, 'status' => 'open']);
        Sanctum::actingAs($member);
        $this->deleteJson($this->endpoint($list, $recipe))->assertOk()
            ->assertJsonPath('data.is_shared_with_me', true)->assertJsonPath('data.recipes_count', 0);
        $this->assertSame('open', $own->refresh()->status);
    }

    public function test_authenticated_public_list_user_can_remove_recipe_without_membership(): void
    {
        $recipe = $this->recipe($this->ingredient(), 2);
        $list = $this->add($recipe);
        $list->update(['visibility' => 'public']);
        Sanctum::actingAs($this->user('other'));
        $this->deleteJson($this->endpoint($list, $recipe))->assertOk()->assertJsonPath('data.recipes_count', 0);
    }

    public function test_private_list_member_and_unrelated_shared_list_user_cannot_remove_recipe(): void
    {
        $recipe = $this->recipe($this->ingredient(), 2);
        $list = $this->add($recipe);
        $other = $this->user('other');
        $list->users()->attach($other->id);
        Sanctum::actingAs($other);
        $snapshot = $this->snapshot();
        $this->deleteJson($this->endpoint($list, $recipe))->assertForbidden();
        $this->assertEquals($snapshot, $this->snapshot());
        $list->update(['visibility' => 'shared']);
        $list->users()->detach($other->id);
        $snapshot = $this->snapshot();
        $this->deleteJson($this->endpoint($list, $recipe))->assertForbidden();
        $this->assertEquals($snapshot, $this->snapshot());
    }

    public function test_closed_list_cannot_be_modified_by_creator_shared_member_or_public_user(): void
    {
        $recipe = $this->recipe($this->ingredient(), 2);
        $list = $this->add($recipe);
        $member = $this->user('member');
        $list->users()->attach($member->id);
        foreach (['private', 'shared', 'public'] as $visibility) {
            $list->update(['visibility' => $visibility, 'status' => 'closed', 'closed_at' => now()]);
            $snapshot = $this->snapshot();
            foreach ([$this->owner, $member] as $user) {
                Sanctum::actingAs($user);
                $this->deleteJson($this->endpoint($list, $recipe))->assertForbidden();
                $this->assertEquals($snapshot, $this->snapshot());
            }
        }
    }

    public function test_unattached_or_already_removed_recipe_returns_not_found_without_further_changes(): void
    {
        $recipe = $this->recipe($this->ingredient(), 2);
        $list = $this->add($recipe);
        $unattached = $this->recipe();
        $snapshot = $this->snapshot();
        $this->deleteJson($this->endpoint($list, $unattached))->assertNotFound();
        $this->assertEquals($snapshot, $this->snapshot());
        $this->deleteJson($this->endpoint($list, $recipe))->assertOk();
        $snapshot = $this->snapshot();
        $this->deleteJson($this->endpoint($list, $recipe))->assertNotFound();
        $this->assertEquals($snapshot, $this->snapshot());
    }

    public function test_recipe_without_generated_ingredients_can_be_removed_and_manual_item_survives(): void
    {
        $recipe = $this->recipe();
        $list = $this->add($recipe);
        $item = $list->items()->create(['name' => 'Dero', 'quantity_overridden' => true]);
        $this->deleteJson($this->endpoint($list, $recipe))->assertOk()
            ->assertJsonCount(0, 'data.recipes')->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.id', $item->id)->assertJsonPath('data.recipes_count', 0);
    }

    public function test_null_empty_and_none_units_remain_equivalent_without_conversion(): void
    {
        $ingredient = $this->ingredient('Thyme', null);
        $removed = $this->recipe($ingredient, 2);
        $list = $this->add($removed);
        $remaining = $this->recipe($ingredient, 1);
        $this->add($remaining);
        $item = $list->items()->firstOrFail();
        $item->update(['unit' => '']);
        $item->sources()->where('recipe_id', $remaining->id)->update(['unit' => 'none']);
        $this->deleteJson($this->endpoint($list, $removed))->assertOk()
            ->assertJsonPath('data.items.0.quantity', '1.000')->assertJsonPath('data.items.0.unit', '');
        $this->assertNull($ingredient->refresh()->default_unit);
    }

    public function test_integrity_mismatch_rolls_back_relationship_sources_and_already_deleted_items(): void
    {
        $uniqueIngredient = $this->ingredient('Unique');
        $sharedIngredient = $this->ingredient('Shared');
        $removed = $this->recipe($uniqueIngredient, 2);
        $removed->recipeIngredients()->create(['ingredient_id' => $sharedIngredient->id, 'value' => 2, 'unit' => 'piece']);
        $list = $this->add($removed);
        $remaining = $this->recipe($sharedIngredient, 1);
        $this->add($remaining);
        $sharedItem = $list->items()->where('ingredient_id', $sharedIngredient->id)->firstOrFail();
        $source = $sharedItem->sources()->where('recipe_id', $remaining->id)->firstOrFail();

        foreach (['source', 'item', 'canonical'] as $corruption) {
            if ($corruption === 'source') {
                $source->update(['unit' => 'gram']);
            } elseif ($corruption === 'item') {
                $sharedItem->update(['unit' => 'gram']);
            } else {
                $sharedIngredient->update(['default_unit' => 'gram']);
            }
            $snapshot = $this->snapshot();
            $this->deleteJson($this->endpoint($list, $removed))->assertUnprocessable()
                ->assertJsonValidationErrors('items.'.$sharedItem->id.'.unit');
            $this->assertEquals($snapshot, $this->snapshot());
            $source->update(['unit' => 'piece']);
            $sharedItem->update(['unit' => 'piece']);
            $sharedIngredient->update(['default_unit' => 'piece']);
        }
    }

    public function test_generated_item_with_deleted_ingredient_reference_is_not_mistaken_for_a_manual_item(): void
    {
        $ingredient = $this->ingredient();
        $recipe = $this->recipe($ingredient, 2);
        $list = $this->add($recipe);
        $item = $list->items()->firstOrFail();
        $ingredient->delete();
        $this->assertNull($item->refresh()->ingredient_id);
        $this->deleteJson($this->endpoint($list, $recipe))->assertOk()->assertJsonCount(0, 'data.items');
        $this->assertDatabaseMissing('shopping_list_items', ['id' => $item->id]);
    }

    public function test_authentication_and_existing_list_and_recipe_are_required(): void
    {
        $recipe = $this->recipe($this->ingredient(), 2);
        $list = $this->add($recipe);
        auth()->forgetGuards();
        $this->deleteJson($this->endpoint($list, $recipe))->assertUnauthorized();
        Sanctum::actingAs($this->owner);
        $this->deleteJson('/api/shopping-lists/999999/recipes/'.$recipe->id)->assertNotFound();
        $this->deleteJson('/api/shopping-lists/'.$list->id.'/recipes/999999')->assertNotFound();
        $this->assertSame(1, $list->recipes()->count());
    }

    private function user(string $username): User
    {
        return User::create(['username' => $username, 'pin' => '1234']);
    }

    private function ingredient(string $name = 'Onion', ?string $unit = 'piece'): Ingredient
    {
        return Ingredient::create(['name' => $name, 'default_unit' => $unit, 'is_shoppable' => true]);
    }

    private function recipe(?Ingredient $ingredient = null, int|string|null $quantity = null): Recipe
    {
        $recipe = $this->owner->recipes()->create(['name' => 'Recipe']);
        if ($ingredient !== null) {
            $recipe->recipeIngredients()->create([
                'ingredient_id' => $ingredient->id, 'value' => $quantity, 'unit' => $ingredient->default_unit,
                'raw_text' => 'Original ingredient text', 'needs_review' => false,
            ]);
        }

        return $recipe;
    }

    private function add(Recipe $recipe): ShoppingList
    {
        $response = $this->postJson('/api/shopping-lists/active/recipes/'.$recipe->id)->assertCreated();

        return ShoppingList::findOrFail($response->json('data.id'));
    }

    private function endpoint(ShoppingList $list, Recipe $recipe): string
    {
        return '/api/shopping-lists/'.$list->id.'/recipes/'.$recipe->id;
    }

    private function snapshot(): array
    {
        $snapshot = [];
        foreach (['shopping_lists', 'shopping_list_recipes', 'shopping_list_items', 'shopping_list_item_sources', 'recipe_ingredients', 'ingredients', 'recipes'] as $table) {
            $snapshot[$table] = DB::table($table)->orderBy('id')->get()->toArray();
        }

        return $snapshot;
    }
}
