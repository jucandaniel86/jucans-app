<?php

namespace Tests\Feature;

use App\Models\Ingredient;
use App\Models\ShoppingCategory;
use App\Models\ShoppingList;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShoppingListDataFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_shopping_list_models_expose_the_expected_relationships_and_casts(): void
    {
        $creator = $this->createUser('creator');
        $sharedUser = $this->createUser('shared');
        $recipe = $creator->recipes()->create(['name' => 'Supă']);
        $category = ShoppingCategory::create(['name' => 'Legume', 'sort_order' => 1]);
        $ingredient = Ingredient::create([
            'name' => 'Morcov',
            'shopping_category_id' => $category->id,
        ]);
        $list = ShoppingList::create([
            'name' => 'Cumpărături weekend',
            'created_by' => $creator->id,
        ]);
        $list->users()->attach($sharedUser->id);
        $list->recipes()->attach($recipe->id, ['added_by' => $creator->id]);
        $item = $list->items()->create([
            'ingredient_id' => $ingredient->id,
            'name' => 'Morcov',
            'calculated_quantity' => 1.5,
            'quantity' => 2,
            'unit' => 'kilogram',
            'shopping_category_id' => $category->id,
            'is_checked' => true,
            'quantity_overridden' => true,
        ]);
        $source = $item->sources()->create([
            'recipe_id' => $recipe->id,
            'quantity' => 1.5,
            'unit' => 'kilogram',
        ]);

        $this->assertTrue($list->creator->is($creator));
        $this->assertTrue($list->users->contains($sharedUser));
        $this->assertTrue($list->recipes->contains($recipe));
        $this->assertSame($creator->id, $list->recipes->first()->pivot->added_by);
        $this->assertTrue($list->items->contains($item));
        $this->assertTrue($item->shoppingList->is($list));
        $this->assertTrue($item->ingredient->is($ingredient));
        $this->assertTrue($item->shoppingCategory->is($category));
        $this->assertTrue($item->sources->contains($source));
        $this->assertTrue($source->shoppingListItem->is($item));
        $this->assertTrue($source->recipe->is($recipe));
        $this->assertSame('1.500', $item->calculated_quantity);
        $this->assertSame('2.000', $item->quantity);
        $this->assertSame('1.500', $source->quantity);
        $this->assertTrue($item->is_checked);
        $this->assertTrue($item->quantity_overridden);
        $this->assertTrue($creator->createdShoppingLists->contains($list));
        $this->assertTrue($sharedUser->shoppingLists->contains($list));
        $this->assertTrue($recipe->shoppingLists->contains($list));
        $this->assertTrue($recipe->shoppingListItemSources->contains($source));
        $this->assertTrue($ingredient->shoppingListItems->contains($item));
        $this->assertTrue($category->shoppingListItems->contains($item));
    }

    public function test_defaults_and_manual_items_are_supported(): void
    {
        $creator = $this->createUser('creator');
        $list = ShoppingList::create(['created_by' => $creator->id]);
        $item = $list->items()->create(['name' => 'Săpun']);
        $list->refresh();
        $item->refresh();

        $this->assertNull($list->name);
        $this->assertSame('open', $list->status);
        $this->assertSame('private', $list->visibility);
        $this->assertNull($list->closed_at);
        $this->assertNull($item->ingredient_id);
        $this->assertNull($item->ingredient);
        $this->assertNull($item->calculated_quantity);
        $this->assertNull($item->quantity);
        $this->assertNull($item->unit);
        $this->assertNull($item->shopping_category_id);
        $this->assertFalse($item->is_checked);
        $this->assertFalse($item->quantity_overridden);
        $this->assertCount(0, $item->sources);

        $list->update(['status' => 'closed', 'closed_at' => '2026-10-01 12:00:00']);

        $this->assertInstanceOf(CarbonInterface::class, $list->fresh()->closed_at);
    }

    public function test_same_user_cannot_be_attached_to_a_list_twice(): void
    {
        $creator = $this->createUser('creator');
        $list = ShoppingList::create(['created_by' => $creator->id]);
        $list->users()->attach($creator->id);

        $this->expectException(QueryException::class);

        $list->users()->attach($creator->id);
    }

    public function test_same_recipe_cannot_be_attached_to_a_list_twice(): void
    {
        $creator = $this->createUser('creator');
        $recipe = $creator->recipes()->create(['name' => 'Supă']);
        $list = ShoppingList::create(['created_by' => $creator->id]);
        $list->recipes()->attach($recipe->id, ['added_by' => $creator->id]);

        $this->expectException(QueryException::class);

        $list->recipes()->attach($recipe->id, ['added_by' => $creator->id]);
    }

    public function test_deleting_a_list_cascades_pivots_items_and_item_sources(): void
    {
        $creator = $this->createUser('creator');
        $sharedUser = $this->createUser('shared');
        $recipe = $creator->recipes()->create(['name' => 'Supă']);
        $list = ShoppingList::create(['created_by' => $creator->id]);
        $list->users()->attach($sharedUser->id);
        $list->recipes()->attach($recipe->id, ['added_by' => $creator->id]);
        $item = $list->items()->create(['name' => 'Morcov']);
        $source = $item->sources()->create(['recipe_id' => $recipe->id]);

        $list->delete();

        $this->assertDatabaseMissing('shopping_lists', ['id' => $list->id]);
        $this->assertDatabaseMissing('shopping_list_users', ['shopping_list_id' => $list->id]);
        $this->assertDatabaseMissing('shopping_list_recipes', ['shopping_list_id' => $list->id]);
        $this->assertDatabaseMissing('shopping_list_items', ['id' => $item->id]);
        $this->assertDatabaseMissing('shopping_list_item_sources', ['id' => $source->id]);
        $this->assertDatabaseHas('recipes', ['id' => $recipe->id]);
        $this->assertDatabaseHas('users', ['id' => $sharedUser->id]);
    }

    public function test_deleting_optional_references_sets_item_foreign_keys_to_null(): void
    {
        $creator = $this->createUser('creator');
        $category = ShoppingCategory::create(['name' => 'Igienă']);
        $ingredient = Ingredient::create([
            'name' => 'Săpun',
            'shopping_category_id' => $category->id,
        ]);
        $list = ShoppingList::create(['created_by' => $creator->id]);
        $item = $list->items()->create([
            'ingredient_id' => $ingredient->id,
            'name' => 'Săpun',
            'shopping_category_id' => $category->id,
        ]);

        $ingredient->delete();
        $category->delete();

        $item->refresh();
        $this->assertNull($item->ingredient_id);
        $this->assertNull($item->shopping_category_id);
        $this->assertDatabaseHas('shopping_list_items', [
            'id' => $item->id,
            'name' => 'Săpun',
            'ingredient_id' => null,
            'shopping_category_id' => null,
        ]);
    }

    private function createUser(string $username): User
    {
        return User::create([
            'username' => $username,
            'pin' => '1234',
        ]);
    }
}
