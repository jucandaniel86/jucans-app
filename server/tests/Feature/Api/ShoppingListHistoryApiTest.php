<?php

namespace Tests\Feature\Api;

use App\Models\Ingredient;
use App\Models\ShoppingList;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ShoppingListHistoryApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_filters_access_orders_open_then_closed_and_paginates_summaries(): void
    {
        $user = $this->user('shopper');
        $owner = $this->user('owner');
        $older = $this->list($user, ['status' => 'closed', 'closed_at' => '2026-09-21 12:00:00']);
        $open = $this->list($user);
        $newer = $this->list($owner, ['status' => 'closed', 'visibility' => 'shared', 'closed_at' => '2026-09-28 12:00:00']);
        $newer->users()->attach($user->id);
        $this->list($owner);
        $open->items()->createMany([['name' => 'Milk', 'is_checked' => true], ['name' => 'Thyme']]);
        Sanctum::actingAs($user);

        $this->getJson('/api/shopping-lists?per_page=2')->assertOk()
            ->assertJsonPath('meta.total', 3)->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('data.0.id', $open->id)->assertJsonPath('data.1.id', $newer->id)
            ->assertJsonPath('data.0.items_count', 2)->assertJsonPath('data.0.unchecked_items_count', 1)
            ->assertJsonPath('data.0.recipes_count', 0)->assertJsonMissingPath('data.0.items');
        $this->getJson('/api/shopping-lists?per_page=2&page=2')->assertOk()->assertJsonPath('data.0.id', $older->id);
        $this->getJson('/api/shopping-lists?per_page=101')->assertUnprocessable();
    }

    public function test_detail_returns_open_and_closed_items_categories_and_sources_to_members(): void
    {
        $user = $this->user('shopper');
        $owner = $this->user('owner');
        Sanctum::actingAs($user);
        foreach (['open', 'closed'] as $status) {
            $list = $this->list($owner, ['status' => $status, 'visibility' => 'shared', 'closed_at' => $status === 'closed' ? now() : null]);
            $list->users()->attach($user->id);
            $recipe = $owner->recipes()->create(['name' => 'Recipe']);
            $item = $list->items()->create(['name' => 'Milk', 'quantity' => 2, 'is_checked' => true]);
            $source = $item->sources()->create(['recipe_id' => $recipe->id, 'quantity' => 2, 'unit' => 'liter']);
            $this->getJson('/api/shopping-lists/'.$list->id)->assertOk()
                ->assertJsonPath('data.status', $status)->assertJsonPath('data.items.0.id', $item->id)
                ->assertJsonPath('data.items.0.quantity', '2.000')->assertJsonPath('data.items.0.is_checked', true)
                ->assertJsonPath('data.items.0.shopping_category', null)
                ->assertJsonPath('data.items.0.sources.0.id', $source->id);
        }
    }

    public function test_inaccessible_details_are_rejected_and_routes_require_authentication(): void
    {
        $list = $this->list($this->user('owner'));
        $this->getJson('/api/shopping-lists')->assertUnauthorized();
        $this->getJson('/api/shopping-lists/'.$list->id)->assertUnauthorized();
        $this->postJson('/api/shopping-lists/active/close')->assertUnauthorized();
        Sanctum::actingAs($this->user('other'));
        $this->getJson('/api/shopping-lists/'.$list->id)->assertNotFound();
        $this->postJson('/api/shopping-lists/active/close')->assertNoContent();
        $this->assertSame('open', $list->refresh()->status);
    }

    public function test_close_preserves_all_composition_and_repeated_close_does_not_change_closed_timestamp(): void
    {
        $user = $this->user('shopper');
        $list = $this->list($user);
        $recipe = $user->recipes()->create(['name' => 'Recipe']);
        $list->recipes()->attach($recipe->id, ['added_by' => $user->id]);
        $item = $list->items()->create(['name' => 'Milk', 'quantity' => 5, 'calculated_quantity' => 2, 'quantity_overridden' => true, 'is_checked' => true]);
        $item->sources()->create(['recipe_id' => $recipe->id, 'quantity' => 2, 'unit' => 'liter']);
        $snapshots = [];
        foreach (['shopping_list_items', 'shopping_list_recipes', 'shopping_list_item_sources'] as $table) {
            $snapshots[$table] = DB::table($table)->get()->toArray();
        }
        Sanctum::actingAs($user);
        $this->postJson('/api/shopping-lists/active/close')->assertOk()
            ->assertJsonPath('data.status', 'closed')->assertJsonPath('data.id', $list->id);
        $closedAt = $list->refresh()->closed_at;
        $this->assertNotNull($closedAt);
        $this->assertTrue($closedAt->equalTo(now()->startOfSecond()));
        foreach ($snapshots as $table => $snapshot) {
            $this->assertEquals($snapshot, DB::table($table)->get()->toArray());
        }
        $this->getJson('/api/shopping-lists/active')->assertExactJson(['data' => null]);
        $this->travel(1)->hours();
        $this->postJson('/api/shopping-lists/active/close')->assertNoContent();
        $this->assertTrue($closedAt->equalTo($list->refresh()->closed_at));
        $this->assertDatabaseCount('shopping_lists', 1);
        $this->patchJson('/api/shopping-lists/active/items/'.$item->id, ['is_checked' => false])->assertNotFound();
    }

    public function test_only_creator_can_close_and_recipe_add_creates_new_list_without_mutating_shared_history(): void
    {
        $owner = $this->user('owner');
        $user = $this->user('member');
        $list = $this->list($owner, ['visibility' => 'shared']);
        $list->users()->attach($user->id);
        $ingredient = Ingredient::create(['name' => 'Milk', 'default_unit' => 'liter', 'is_shoppable' => true]);
        $recipe = $user->recipes()->create(['name' => 'Recipe']);
        $recipe->recipeIngredients()->create(['ingredient_id' => $ingredient->id, 'value' => 2, 'unit' => 'liter']);
        Sanctum::actingAs($user);
        $this->postJson('/api/shopping-lists/active/recipes/'.$recipe->id)->assertCreated();
        $before = $list->items()->get()->toArray();
        $this->postJson('/api/shopping-lists/active/close')->assertForbidden();
        Sanctum::actingAs($owner);
        $this->postJson('/api/shopping-lists/active/close')->assertOk();
        Sanctum::actingAs($user);
        $this->assertDatabaseCount('shopping_lists', 1);
        $this->postJson('/api/shopping-lists/active/recipes/'.$recipe->id)->assertCreated();
        $this->assertDatabaseCount('shopping_lists', 2);
        $this->assertSame($before, $list->items()->get()->toArray());
        $this->assertSame('closed', $list->refresh()->status);
    }

    private function user(string $username): User
    {
        return User::create(['username' => $username, 'pin' => '1234']);
    }

    private function list(User $user, array $attributes = []): ShoppingList
    {
        return ShoppingList::create(['created_by' => $user->id, 'status' => 'open', 'visibility' => 'private', ...$attributes]);
    }
}
