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

    public function test_authenticated_user_can_add_manual_item_to_accessible_active_open_list(): void
    {
        $owner = User::create(['username' => 'owner', 'pin' => '1234']);
        $user = User::create(['username' => 'shopper', 'pin' => '1234']);
        $list = ShoppingList::create(['created_by' => $owner->id, 'status' => 'open', 'visibility' => 'shared']);
        $list->users()->attach($user->id);
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/shopping-lists/active/items', [
            'name' => '  Dero  ',
            'quantity' => 1,
            'unit' => 'buc',
        ])->assertCreated()
            ->assertJsonPath('data.ingredient_id', null)
            ->assertJsonPath('data.name', 'Dero')
            ->assertJsonPath('data.quantity', '1.000')
            ->assertJsonPath('data.unit', 'buc')
            ->assertJsonPath('data.calculated_quantity', null)
            ->assertJsonPath('data.shopping_category', null)
            ->assertJsonPath('data.is_checked', false)
            ->assertJsonPath('data.quantity_overridden', true);

        $itemId = $response->json('data.id');
        $this->assertDatabaseHas('shopping_list_items', [
            'id' => $itemId,
            'shopping_list_id' => $list->id,
            'ingredient_id' => null,
            'name' => 'Dero',
            'quantity' => '1.000',
            'unit' => 'buc',
            'shopping_category_id' => null,
            'is_checked' => false,
            'quantity_overridden' => true,
        ]);
        $this->assertDatabaseMissing('shopping_list_item_sources', ['shopping_list_item_id' => $itemId]);

        $this->getJson('/api/shopping-lists/active')->assertOk()
            ->assertJsonPath('data.id', $list->id)
            ->assertJsonPath('data.items.0.id', $itemId)
            ->assertJsonPath('data.items.0.name', 'Dero')
            ->assertJsonPath('data.items.0.shopping_category', null)
            ->assertJsonPath('data.items_count', 1)
            ->assertJsonPath('data.unchecked_items_count', 1);
    }

    public function test_manual_item_can_be_created_without_quantity_or_unit(): void
    {
        $user = User::create(['username' => 'shopper', 'pin' => '1234']);
        $list = ShoppingList::create(['created_by' => $user->id, 'status' => 'open', 'visibility' => 'private']);
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/shopping-lists/active/items', [
            'name' => 'Servetele',
        ])->assertCreated()
            ->assertJsonPath('data.name', 'Servetele')
            ->assertJsonPath('data.quantity', null)
            ->assertJsonPath('data.unit', null);

        $this->assertDatabaseHas('shopping_list_items', [
            'id' => $response->json('data.id'),
            'shopping_list_id' => $list->id,
            'ingredient_id' => null,
            'quantity' => null,
            'unit' => null,
            'quantity_overridden' => true,
        ]);
    }

    public function test_manual_item_cannot_be_added_without_accessible_active_open_list(): void
    {
        $user = User::create(['username' => 'shopper', 'pin' => '1234']);
        ShoppingList::create(['created_by' => $user->id, 'status' => 'closed', 'visibility' => 'private']);
        Sanctum::actingAs($user);

        $this->postJson('/api/shopping-lists/active/items', ['name' => 'Dero'])->assertNotFound();

        $other = User::create(['username' => 'other', 'pin' => '1234']);
        ShoppingList::create(['created_by' => $other->id, 'status' => 'open', 'visibility' => 'private']);

        $this->postJson('/api/shopping-lists/active/items', ['name' => 'Dero'])->assertNotFound();
        $this->assertDatabaseCount('shopping_list_items', 0);
    }

    public function test_manual_item_name_is_required_after_trimming_and_quantity_must_be_positive(): void
    {
        $user = User::create(['username' => 'shopper', 'pin' => '1234']);
        ShoppingList::create(['created_by' => $user->id, 'status' => 'open', 'visibility' => 'private']);
        Sanctum::actingAs($user);

        $this->postJson('/api/shopping-lists/active/items', ['name' => '   '])
            ->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->postJson('/api/shopping-lists/active/items', ['name' => 'Dero', 'quantity' => 0])
            ->assertUnprocessable()->assertJsonValidationErrors('quantity');
    }

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

    public function test_recipe_item_quantity_override_does_not_modify_recipe_sources_or_metadata(): void
    {
        [$user, $item] = $this->fixture();
        $recipe = $user->recipes()->create(['name' => 'Recipe']);
        $item->sources()->create(['recipe_id' => $recipe->id, 'quantity' => '0.750', 'unit' => 'liter']);
        $sources = DB::table('shopping_list_item_sources')->get()->toArray();
        Sanctum::actingAs($user);

        $this->patchJson($this->endpoint($item), [
            'is_checked' => true, 'quantity' => 999, 'calculated_quantity' => 999,
            'unit' => 'gram', 'ingredient_id' => null, 'shopping_category_id' => null,
            'quantity_overridden' => false, 'name' => 'Changed', 'sources' => [],
        ])->assertOk()->assertJsonPath('data.quantity', '999.000')
            ->assertJsonPath('data.calculated_quantity', '0.750')
            ->assertJsonPath('data.unit', 'liter')->assertJsonPath('data.quantity_overridden', true)
            ->assertJsonPath('data.name', 'Milk')
            ->assertJsonPath('data.shopping_category.id', $item->shopping_category_id);

        $after = (array) DB::table('shopping_list_items')->find($item->id);
        $this->assertSame('Milk', $after['name']);
        $this->assertEquals('999.000', $after['quantity']);
        $this->assertEquals('0.750', $after['calculated_quantity']);
        $this->assertSame('liter', $after['unit']);
        $this->assertSame(1, $after['quantity_overridden']);
        $this->assertEquals($sources, DB::table('shopping_list_item_sources')->get()->toArray());
    }

    public function test_shared_member_can_update_the_active_open_list(): void
    {
        [$owner, $item] = $this->fixture();
        $member = User::create(['username' => 'member', 'pin' => '1234']);
        $item->shoppingList->update(['visibility' => 'shared']);
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

    public function test_multiple_accessible_open_lists_require_explicit_selection_before_checking_items(): void
    {
        [$user, $item] = $this->fixture();
        ShoppingList::create(['created_by' => $user->id, 'status' => 'open']);
        Sanctum::actingAs($user);
        $this->patchJson($this->endpoint($item), ['is_checked' => true])->assertConflict();
        $this->assertFalse($item->refresh()->is_checked);
    }

    public function test_patch_requires_at_least_one_supported_field_and_checked_must_be_boolean(): void
    {
        [$user, $item] = $this->fixture();
        Sanctum::actingAs($user);
        $this->patchJson($this->endpoint($item), [])->assertUnprocessable()->assertJsonValidationErrors('item');
        foreach ([['is_checked' => null], ['is_checked' => 'yes'], ['is_checked' => []]] as $payload)
            $this->patchJson($this->endpoint($item), $payload)->assertUnprocessable()->assertJsonValidationErrors('is_checked');
        $this->assertFalse($item->refresh()->is_checked);
    }

    public function test_authentication_is_required_and_unknown_items_return_not_found(): void
    {
        [$user, $item] = $this->fixture();
        $this->patchJson($this->endpoint($item), ['is_checked' => true])->assertUnauthorized();
        Sanctum::actingAs($user);
        $this->patchJson('/api/shopping-lists/active/items/999999', ['is_checked' => true])->assertNotFound();
    }

    public function test_manual_item_can_be_edited_and_deleted_on_explicit_open_list(): void
    {
        $user = User::create(['username' => 'shopper', 'pin' => '1234']);
        $list = ShoppingList::create(['created_by' => $user->id, 'status' => 'open']);
        $category = ShoppingCategory::create(['name' => 'Diverse admin', 'sort_order' => 20]);
        $item = $list->items()->create([
            'ingredient_id' => null,
            'shopping_category_id' => $category->id,
            'name' => '  Old  ',
            'quantity' => '1.000',
            'unit' => 'buc',
            'quantity_overridden' => true,
        ]);
        Sanctum::actingAs($user);

        $this->patchJson($this->explicitEndpoint($list, $item), [
            'name' => '  Dero  ',
            'quantity' => 2,
            'unit' => ' cutii ',
        ])->assertOk()
            ->assertJsonPath('data.name', 'Dero')
            ->assertJsonPath('data.quantity', '2.000')
            ->assertJsonPath('data.unit', 'cutii')
            ->assertJsonPath('data.shopping_category.id', $category->id);

        $this->assertDatabaseHas('shopping_list_items', [
            'id' => $item->id,
            'name' => 'Dero',
            'quantity' => '2.000',
            'unit' => 'cutii',
            'shopping_category_id' => $category->id,
            'ingredient_id' => null,
        ]);

        $this->deleteJson($this->explicitEndpoint($list, $item))->assertNoContent();
        $this->assertDatabaseMissing('shopping_list_items', ['id' => $item->id]);
    }

    public function test_recipe_generated_item_cannot_be_deleted_directly_and_can_reset_override(): void
    {
        [$user, $item] = $this->fixture();
        $recipe = $user->recipes()->create(['name' => 'Recipe']);
        $item->sources()->create(['recipe_id' => $recipe->id, 'quantity' => '0.750', 'unit' => 'liter']);
        Sanctum::actingAs($user);

        $this->deleteJson($this->explicitEndpoint($item->shoppingList, $item))->assertUnprocessable();
        $this->assertDatabaseHas('shopping_list_items', ['id' => $item->id]);

        $this->patchJson($this->explicitEndpoint($item->shoppingList, $item), [
            'reset_quantity' => true,
        ])->assertOk()
            ->assertJsonPath('data.quantity', '0.750')
            ->assertJsonPath('data.calculated_quantity', '0.750')
            ->assertJsonPath('data.quantity_overridden', false);
    }

    public function test_shared_authorized_user_can_edit_and_closed_or_unauthorized_lists_are_rejected(): void
    {
        $owner = User::create(['username' => 'owner', 'pin' => '1234']);
        $member = User::create(['username' => 'member', 'pin' => '1234']);
        $other = User::create(['username' => 'other', 'pin' => '1234']);
        $list = ShoppingList::create(['created_by' => $owner->id, 'status' => 'open', 'visibility' => 'shared']);
        $list->users()->attach($member->id);
        $item = $list->items()->create(['name' => 'Dero', 'ingredient_id' => null, 'quantity_overridden' => true]);

        Sanctum::actingAs($member);
        $this->patchJson($this->explicitEndpoint($list, $item), ['name' => 'Saci'])
            ->assertOk()->assertJsonPath('data.name', 'Saci');

        Sanctum::actingAs($other);
        $this->patchJson($this->explicitEndpoint($list, $item), ['name' => 'Nope'])->assertForbidden();

        Sanctum::actingAs($owner);
        $list->update(['status' => 'closed']);
        $this->patchJson($this->explicitEndpoint($list, $item), ['name' => 'Nope'])->assertForbidden();
        $this->deleteJson($this->explicitEndpoint($list, $item))->assertForbidden();
    }

    public function test_txt_export_contains_only_unchecked_grouped_items_with_current_quantities(): void
    {
        $user = User::create(['username' => 'shopper', 'pin' => '1234']);
        $other = User::create(['username' => 'other', 'pin' => '1234']);
        $list = ShoppingList::create(['created_by' => $user->id, 'status' => 'open']);
        $otherList = ShoppingList::create(['created_by' => $other->id, 'status' => 'open']);
        $vegetables = ShoppingCategory::create(['name' => 'Legume & fructe', 'emoji' => '🥬', 'sort_order' => 1]);
        $meat = ShoppingCategory::create(['name' => 'Carne', 'emoji' => '🥩', 'sort_order' => 2]);
        $spices = ShoppingCategory::create(['name' => 'Condimente & sosuri', 'emoji' => '🧂', 'sort_order' => 3]);
        $list->items()->create(['name' => 'Morcovi', 'quantity' => '3.000', 'unit' => 'buc.', 'shopping_category_id' => $vegetables->id]);
        $list->items()->create(['name' => 'Ceapă', 'quantity' => '2.000', 'unit' => 'buc.', 'shopping_category_id' => $vegetables->id]);
        $list->items()->create([
            'name' => 'Piept de pui',
            'quantity' => '1.000',
            'calculated_quantity' => '0.600',
            'unit' => 'kg',
            'shopping_category_id' => $meat->id,
            'quantity_overridden' => true,
        ]);
        $list->items()->create(['name' => 'Sare', 'quantity' => null, 'unit' => 'to_taste', 'shopping_category_id' => $spices->id]);
        $list->items()->create(['name' => 'Dero', 'quantity' => null, 'unit' => null]);
        $list->items()->create(['name' => 'Lapte', 'quantity' => '1.000', 'unit' => 'l', 'is_checked' => true]);
        $otherList->items()->create(['name' => 'Altă listă', 'quantity' => '9.000', 'unit' => 'buc.']);
        Sanctum::actingAs($user);

        $response = $this->get('/api/shopping-lists/'.$list->id.'/export')->assertOk()
            ->assertHeader('Content-Type', 'text/plain; charset=UTF-8');

        $this->assertSame(<<<'TXT'
LISTA DE CUMPĂRĂTURI

🥬 Legume & fructe
Ceapă — 2 buc.
Morcovi — 3 buc.

🥩 Carne
Piept de pui — 1 kg

🧂 Condimente & sosuri
Sare — după gust

📦 Diverse
Dero

TXT, $response->getContent());
        $this->assertStringNotContainsString('Lapte', $response->getContent());
        $this->assertStringNotContainsString('Altă listă', $response->getContent());
        $this->assertStringNotContainsString('quantity_overridden', $response->getContent());
    }

    public function test_txt_export_empty_and_unauthorized_states(): void
    {
        $user = User::create(['username' => 'shopper', 'pin' => '1234']);
        $other = User::create(['username' => 'other', 'pin' => '1234']);
        $list = ShoppingList::create(['created_by' => $user->id, 'status' => 'open']);
        $list->items()->create(['name' => 'Lapte', 'is_checked' => true]);
        $private = ShoppingList::create(['created_by' => $other->id, 'status' => 'open']);
        $private->items()->create(['name' => 'Secret']);
        Sanctum::actingAs($user);

        $this->get('/api/shopping-lists/'.$list->id.'/export')->assertNoContent();
        $this->get('/api/shopping-lists/'.$private->id.'/export')->assertNotFound();
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

    private function explicitEndpoint(ShoppingList $list, ShoppingListItem $item): string
    {
        return '/api/shopping-lists/'.$list->id.'/items/'.$item->id;
    }
}
