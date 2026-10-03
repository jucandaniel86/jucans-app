<?php

namespace Tests\Feature\Api;

use App\Models\Ingredient;
use App\Models\ShoppingCategory;
use App\Models\ShoppingList;
use App\Models\ShoppingListItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ShoppingListItemApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_check_uncheck_and_repeat_requests_with_correct_counts(): void
    {
        [$user, $item] = $this->fixture();
        Sanctum::actingAs($user);
        $this->patchJson($this->endpoint($item), ['is_checked' => true])->assertOk()
            ->assertJsonPath('data.id', $item->id)->assertJsonPath('data.is_checked', true);
        $this->getJson('/api/shopping-lists/active')->assertOk()
            ->assertJsonPath('data.items_count', 1)->assertJsonPath('data.unchecked_items_count', 0);
        $this->patchJson($this->endpoint($item), ['is_checked' => true])->assertOk();
        $this->patchJson($this->endpoint($item), ['is_checked' => false])->assertOk()
            ->assertJsonPath('data.is_checked', false);
        $this->getJson('/api/shopping-lists/active')->assertOk()
            ->assertJsonPath('data.items_count', 1)->assertJsonPath('data.unchecked_items_count', 1);
    }

    public function test_only_checked_column_changes_and_extra_fields_cannot_modify_item_or_sources(): void
    {
        [$user, $item] = $this->fixture();
        $recipe = $user->recipes()->create(['name' => 'Recipe']);
        $item->sources()->create(['recipe_id' => $recipe->id, 'quantity' => '0.750', 'unit' => 'liter']);
        $before = (array) DB::table('shopping_list_items')->find($item->id);
        $sources = DB::table('shopping_list_item_sources')->get()->toArray();
        Sanctum::actingAs($user);

        $this->patchJson($this->endpoint($item), [
            'is_checked' => true, 'quantity' => 999, 'calculated_quantity' => 999,
            'unit' => 'gram', 'ingredient_id' => null, 'shopping_category_id' => null,
            'quantity_overridden' => false, 'name' => 'Changed', 'sources' => [],
        ])->assertOk()->assertJsonPath('data.quantity', '1.500')
            ->assertJsonPath('data.calculated_quantity', '0.750')
            ->assertJsonPath('data.unit', 'liter')->assertJsonPath('data.quantity_overridden', true)
            ->assertJsonPath('data.shopping_category.id', $item->shopping_category_id);

        $after = (array) DB::table('shopping_list_items')->find($item->id);
        unset($before['is_checked'], $after['is_checked']);
        $this->assertSame($before, $after);
        $this->assertEquals($sources, DB::table('shopping_list_item_sources')->get()->toArray());
    }

    public function test_shared_member_can_update_the_active_open_list(): void
    {
        [$owner, $item] = $this->fixture();
        $member = User::create(['username' => 'member', 'pin' => '1234']);
        $item->shoppingList->users()->attach($member->id);
        Sanctum::actingAs($member);
        $this->patchJson($this->endpoint($item), ['is_checked' => true])->assertOk();
        $this->assertTrue($item->refresh()->is_checked);
    }

    public function test_inaccessible_private_list_item_is_rejected(): void
    {
        [$owner, $item] = $this->fixture();
        $other = User::create(['username' => 'other', 'pin' => '1234']);
        ShoppingList::create(['created_by' => $other->id, 'status' => 'open']);
        Sanctum::actingAs($other);
        $this->patchJson($this->endpoint($item), ['is_checked' => true])->assertNotFound();
        $this->assertFalse($item->refresh()->is_checked);
    }

    public function test_closed_list_item_is_rejected_even_for_creator(): void
    {
        [$user, $item] = $this->fixture();
        $item->shoppingList->update(['status' => 'closed']);
        Sanctum::actingAs($user);
        $this->patchJson($this->endpoint($item), ['is_checked' => true])->assertNotFound();
        $this->assertFalse($item->refresh()->is_checked);
    }

    public function test_item_from_an_older_open_list_is_not_part_of_the_selected_active_list(): void
    {
        [$user, $item] = $this->fixture();
        ShoppingList::create(['created_by' => $user->id, 'status' => 'open']);
        Sanctum::actingAs($user);
        $this->patchJson($this->endpoint($item), ['is_checked' => true])->assertNotFound();
    }

    public function test_checked_value_is_required_and_must_be_boolean(): void
    {
        [$user, $item] = $this->fixture();
        Sanctum::actingAs($user);
        foreach ([[], ['is_checked' => null], ['is_checked' => 'yes'], ['is_checked' => []]] as $payload) {
            $this->patchJson($this->endpoint($item), $payload)->assertUnprocessable()->assertJsonValidationErrors('is_checked');
        }
        $this->assertFalse($item->refresh()->is_checked);
    }

    public function test_authentication_is_required_and_unknown_items_return_not_found(): void
    {
        [$user, $item] = $this->fixture();
        $this->patchJson($this->endpoint($item), ['is_checked' => true])->assertUnauthorized();
        Sanctum::actingAs($user);
        $this->patchJson('/api/shopping-lists/active/items/999999', ['is_checked' => true])->assertNotFound();
    }

    private function fixture(): array
    {
        $user = User::create(['username' => 'shopper', 'pin' => '1234']);
        $list = ShoppingList::create(['created_by' => $user->id, 'status' => 'open', 'visibility' => 'private']);
        $category = ShoppingCategory::create(['name' => 'Dairy', 'sort_order' => 1]);
        $ingredient = Ingredient::create(['name' => 'Milk', 'default_unit' => 'liter']);
        $item = $list->items()->create([
            'ingredient_id' => $ingredient->id, 'shopping_category_id' => $category->id,
            'name' => 'Milk', 'unit' => 'liter', 'quantity' => '1.500', 'calculated_quantity' => '0.750',
            'quantity_overridden' => true, 'is_checked' => false,
        ]);

        return [$user, $item];
    }

    private function endpoint(ShoppingListItem $item): string
    {
        return '/api/shopping-lists/active/items/'.$item->id;
    }
}
