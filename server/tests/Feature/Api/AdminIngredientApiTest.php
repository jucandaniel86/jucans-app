<?php

namespace Tests\Feature\Api;

use App\Models\Ingredient;
use App\Models\ShoppingCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminIngredientApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_list_and_search_paginated_ingredients(): void
    {
        $category = ShoppingCategory::create([
            'name' => 'Condimente',
            'emoji' => '🧂',
            'sort_order' => 10,
        ]);
        $salt = Ingredient::create([
            'name' => 'Sare',
            'default_unit' => 'gram',
            'shopping_category_id' => $category->id,
            'is_shoppable' => true,
        ]);
        $salt->aliases()->create(['alias' => 'Sare fină']);
        $pepper = Ingredient::create(['name' => 'Piper']);
        Sanctum::actingAs($this->createUser(true));

        $this->getJson('/api/admin/ingredients?search=fina&per_page=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $salt->id)
            ->assertJsonPath('data.0.default_unit', 'gram')
            ->assertJsonPath('data.0.is_shoppable', true)
            ->assertJsonPath('data.0.shopping_category.name', 'Condimente')
            ->assertJsonPath('data.0.shopping_category.emoji', '🧂')
            ->assertJsonPath('data.0.aliases.0.alias', 'Sare fină')
            ->assertJsonPath('meta.per_page', 1)
            ->assertJsonPath('meta.total', 1);

        $this->getJson('/api/admin/ingredients?per_page=1')
            ->assertOk()
            ->assertJsonPath('data.0.id', $pepper->id)
            ->assertJsonPath('data.0.default_unit', null)
            ->assertJsonPath('data.0.shopping_category', null)
            ->assertJsonPath('data.0.aliases', [])
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('meta.total', 2);
    }

    public function test_admin_can_update_an_ingredient(): void
    {
        $category = ShoppingCategory::create([
            'name' => 'Legume',
            'emoji' => '🥕',
            'sort_order' => 20,
        ]);
        $ingredient = Ingredient::create(['name' => 'Morcov']);
        $ingredient->aliases()->create(['alias' => 'Morcovi']);
        Sanctum::actingAs($this->createUser(true));

        $this->patchJson("/api/admin/ingredients/{$ingredient->id}", [
            'name' => 'Morcov proaspăt',
            'default_unit' => 'piece',
            'shopping_category_id' => $category->id,
            'is_shoppable' => false,
        ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Morcov proaspăt')
            ->assertJsonPath('data.default_unit', 'piece')
            ->assertJsonPath('data.shopping_category.id', $category->id)
            ->assertJsonPath('data.is_shoppable', false)
            ->assertJsonPath('data.aliases.0.alias', 'Morcovi');

        $this->assertDatabaseHas('ingredients', [
            'id' => $ingredient->id,
            'name' => 'Morcov proaspăt',
            'normalized_name' => 'morcov proaspat',
            'default_unit' => 'piece',
            'shopping_category_id' => $category->id,
            'is_shoppable' => false,
        ]);
    }

    public function test_filters_are_composable_and_work_with_search(): void
    {
        $category = ShoppingCategory::create(['name' => 'Condimente']);
        $matching = Ingredient::create([
            'name' => 'Sare fără categorie',
            'default_unit' => null,
            'shopping_category_id' => null,
            'is_shoppable' => false,
        ]);
        Ingredient::create([
            'name' => 'Sare cu unitate',
            'default_unit' => 'gram',
            'shopping_category_id' => null,
            'is_shoppable' => false,
        ]);
        Ingredient::create([
            'name' => 'Piper',
            'default_unit' => null,
            'shopping_category_id' => $category->id,
            'is_shoppable' => false,
        ]);
        Ingredient::create([
            'name' => 'Sare cumpărabilă',
            'default_unit' => null,
            'shopping_category_id' => null,
            'is_shoppable' => true,
        ]);
        Sanctum::actingAs($this->createUser(true));

        $this->getJson(
            '/api/admin/ingredients?search=sare&missing_unit=1&missing_category=1&is_shoppable=0'
        )
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $matching->id)
            ->assertJsonPath('meta.total', 1);
    }

    public function test_ingredient_filters_validate_boolean_query_parameters(): void
    {
        Sanctum::actingAs($this->createUser(true));

        $this->getJson(
            '/api/admin/ingredients?missing_unit=yes&missing_category=no&is_shoppable=maybe'
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'missing_unit',
                'missing_category',
                'is_shoppable',
            ]);
    }

    public function test_shopping_categories_are_ordered_by_sort_order_then_name(): void
    {
        ShoppingCategory::create(['name' => 'Fructe', 'sort_order' => 20]);
        ShoppingCategory::create(['name' => 'Legume', 'sort_order' => 10]);
        ShoppingCategory::create(['name' => 'Condimente', 'sort_order' => 10]);
        Sanctum::actingAs($this->createUser(true));

        $this->getJson('/api/admin/shopping-categories')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.name', 'Condimente')
            ->assertJsonPath('data.1.name', 'Legume')
            ->assertJsonPath('data.2.name', 'Fructe');
    }

    public function test_admin_endpoints_reject_non_admin_and_unauthenticated_users(): void
    {
        $ingredient = Ingredient::create(['name' => 'Sare']);
        Sanctum::actingAs($this->createUser(false));

        $this->getJson('/api/admin/ingredients')->assertForbidden();
        $this->patchJson("/api/admin/ingredients/{$ingredient->id}", [])->assertForbidden();
        $this->getJson('/api/admin/shopping-categories')->assertForbidden();

        auth()->forgetGuards();

        $this->getJson('/api/admin/ingredients')->assertUnauthorized();
        $this->getJson('/api/admin/shopping-categories')->assertUnauthorized();
    }

    public function test_ingredient_update_validates_units_categories_and_duplicate_names(): void
    {
        $ingredient = Ingredient::create(['name' => 'Sare']);
        Ingredient::create(['name' => 'Piper']);
        Sanctum::actingAs($this->createUser(true));

        $this->patchJson("/api/admin/ingredients/{$ingredient->id}", [
            'name' => 'PIPER',
            'default_unit' => 'bucket',
            'shopping_category_id' => 999,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['default_unit', 'shopping_category_id']);

        $this->patchJson("/api/admin/ingredients/{$ingredient->id}", [
            'name' => 'PIPER',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');
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
