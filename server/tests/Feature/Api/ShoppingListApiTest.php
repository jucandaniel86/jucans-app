<?php

namespace Tests\Feature\Api;

use App\Models\Ingredient;
use App\Models\ShoppingCategory;
use App\Models\ShoppingList;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ShoppingListApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_endpoint_returns_null_when_user_has_no_open_accessible_list(): void
    {
        Sanctum::actingAs($this->createUser('daniel'));

        $this->getJson('/api/shopping-lists/active')
            ->assertOk()
            ->assertExactJson(['data' => null]);
    }

    public function test_user_can_create_an_open_private_list(): void
    {
        $user = $this->createUser('daniel');
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/shopping-lists');

        $response
            ->assertCreated()
            ->assertJsonPath('data.name', null)
            ->assertJsonPath('data.status', 'open')
            ->assertJsonPath('data.visibility', 'private')
            ->assertJsonPath('data.created_by', $user->id)
            ->assertJsonPath('data.closed_at', null)
            ->assertJsonPath('data.recipes_count', 0)
            ->assertJsonPath('data.items_count', 0)
            ->assertJsonPath('data.unchecked_items_count', 0)
            ->assertJsonStructure(['data' => ['id', 'created_at']]);

        $listId = $response->json('data.id');
        $this->assertDatabaseHas('shopping_lists', [
            'id' => $listId,
            'created_by' => $user->id,
            'status' => 'open',
            'visibility' => 'private',
        ]);
        $this->assertDatabaseHas('shopping_list_users', [
            'shopping_list_id' => $listId,
            'user_id' => $user->id,
        ]);
    }

    public function test_creator_can_retrieve_their_open_list_as_active(): void
    {
        $creator = $this->createUser('creator');
        $list = $this->createList($creator);
        Sanctum::actingAs($creator);

        $this->getJson('/api/shopping-lists/active')
            ->assertOk()
            ->assertJsonPath('data.id', $list->id)
            ->assertJsonPath('data.created_by', $creator->id);
    }

    public function test_repeated_create_returns_the_existing_open_list(): void
    {
        $user = $this->createUser('daniel');
        Sanctum::actingAs($user);

        $first = $this->postJson('/api/shopping-lists')->assertCreated();
        $second = $this->postJson('/api/shopping-lists')->assertOk();

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertDatabaseCount('shopping_lists', 1);
        $this->assertDatabaseCount('shopping_list_users', 1);
    }

    public function test_attached_user_can_retrieve_a_shared_open_list_with_summary_counts(): void
    {
        $creator = $this->createUser('creator');
        $sharedUser = $this->createUser('shared');
        $recipe = $creator->recipes()->create(['name' => 'Supă']);
        $list = $this->createList($creator);
        $list->users()->attach($sharedUser->id);
        $list->recipes()->attach($recipe->id, ['added_by' => $creator->id]);
        $list->items()->createMany([
            ['name' => 'Morcov', 'is_checked' => false],
            ['name' => 'Sare', 'is_checked' => true],
        ]);
        Sanctum::actingAs($sharedUser);

        $this->getJson('/api/shopping-lists/active')
            ->assertOk()
            ->assertJsonPath('data.id', $list->id)
            ->assertJsonPath('data.recipes_count', 1)
            ->assertJsonPath('data.items_count', 2)
            ->assertJsonPath('data.unchecked_items_count', 1);
    }

    public function test_unrelated_user_cannot_retrieve_another_users_private_open_list(): void
    {
        $creator = $this->createUser('creator');
        $unrelatedUser = $this->createUser('unrelated');
        $this->createList($creator);
        Sanctum::actingAs($unrelatedUser);

        $this->getJson('/api/shopping-lists/active')
            ->assertOk()
            ->assertExactJson(['data' => null]);
    }

    public function test_closed_lists_are_not_returned_as_active(): void
    {
        $creator = $this->createUser('creator');
        $this->createList($creator, [
            'status' => 'closed',
            'closed_at' => now(),
        ]);
        Sanctum::actingAs($creator);

        $this->getJson('/api/shopping-lists/active')
            ->assertOk()
            ->assertExactJson(['data' => null]);
    }

    public function test_repeated_submissions_normally_create_only_one_open_list(): void
    {
        $user = $this->createUser('daniel');
        Sanctum::actingAs($user);

        foreach (range(1, 5) as $attempt) {
            $this->postJson('/api/shopping-lists')
                ->assertStatus($attempt === 1 ? 201 : 200);
        }

        $this->assertSame(1, ShoppingList::query()->where('status', 'open')->count());
    }

    public function test_shopping_list_endpoints_require_authentication(): void
    {
        $this->getJson('/api/shopping-lists/active')->assertUnauthorized();
        $this->postJson('/api/shopping-lists')->assertUnauthorized();
    }

    public function test_active_list_exposes_item_snapshots_quantities_and_categories_without_sources(): void
    {
        $creator = $this->createUser('creator');
        $list = $this->createList($creator);
        $category = ShoppingCategory::create(['name' => 'Dairy', 'emoji' => 'milk', 'sort_order' => 2]);
        $ingredient = Ingredient::create(['name' => 'Milk', 'default_unit' => 'liter']);
        $item = $list->items()->create([
            'ingredient_id' => $ingredient->id,
            'name' => 'Milk snapshot',
            'unit' => 'liter',
            'calculated_quantity' => '0.750',
            'quantity' => '1.500',
            'quantity_overridden' => true,
            'is_checked' => true,
            'shopping_category_id' => $category->id,
        ]);
        $list->items()->create(['name' => 'Thyme', 'quantity' => null]);
        Sanctum::actingAs($creator);

        $this->getJson('/api/shopping-lists/active')->assertOk()
            ->assertJsonCount(2, 'data.items')
            ->assertJsonPath('data.items.0.id', $item->id)
            ->assertJsonPath('data.items.0.ingredient_id', $ingredient->id)
            ->assertJsonPath('data.items.0.name', 'Milk snapshot')
            ->assertJsonPath('data.items.0.calculated_quantity', '0.750')
            ->assertJsonPath('data.items.0.quantity', '1.500')
            ->assertJsonPath('data.items.0.unit', 'liter')
            ->assertJsonPath('data.items.0.is_checked', true)
            ->assertJsonPath('data.items.0.quantity_overridden', true)
            ->assertJsonPath('data.items.0.shopping_category.id', $category->id)
            ->assertJsonPath('data.items.0.shopping_category.sort_order', 2)
            ->assertJsonPath('data.items.1.quantity', null)
            ->assertJsonPath('data.items.1.shopping_category', null)
            ->assertJsonMissingPath('data.items.0.sources');
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createList(User $creator, array $attributes = []): ShoppingList
    {
        return ShoppingList::create([
            'created_by' => $creator->id,
            'status' => 'open',
            'visibility' => 'private',
            ...$attributes,
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
