<?php

namespace Tests\Feature\Api;

use App\Models\Ingredient;
use App\Models\ShoppingList;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ShoppingListMultiListApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_family_can_target_each_open_list_independently(): void
    {
        $daniel = $this->user('Daniel');
        $alina = $this->user('Alina');
        Sanctum::actingAs($daniel);
        $a = $this->postJson('/api/shopping-lists')->assertCreated()->json('data.id');
        Sanctum::actingAs($alina);
        $b = $this->postJson('/api/shopping-lists')->assertCreated()->json('data.id');
        $this->postJson("/api/shopping-lists/$b/users", ['user_id' => $daniel->id])->assertCreated();
        $this->patchJson("/api/shopping-lists/$b/visibility", ['visibility' => 'shared'])->assertOk();
        $ingredient = Ingredient::create(['name' => 'Milk', 'default_unit' => 'liter', 'is_shoppable' => true]);
        $recipe = $alina->recipes()->create(['name' => 'Family recipe']);
        $recipe->recipeIngredients()->create(['ingredient_id' => $ingredient->id, 'value' => 1, 'unit' => 'liter', 'needs_review' => false]);

        Sanctum::actingAs($daniel);
        $open = $this->getJson('/api/shopping-lists/open')->assertOk()->assertJsonCount(2, 'data');
        $this->assertEqualsCanonicalizing([$a, $b], array_column($open->json('data'), 'id'));
        $this->getJson("/api/shopping-lists/$b")->assertOk()->assertJsonPath('data.creator.name', 'Alina');
        $manual = $this->postJson("/api/shopping-lists/$b/items", ['name' => 'Dero'])->assertCreated()->json('data.id');
        $this->postJson("/api/shopping-lists/$b/recipes/$recipe->id")->assertCreated()
            ->assertJsonPath('data.id', $b)->assertJsonCount(2, 'data.items')->assertJsonCount(1, 'data.recipes');
        $this->patchJson("/api/shopping-lists/$b/items/$manual", ['is_checked' => true])->assertOk();
        $this->assertSame(0, ShoppingList::findOrFail($a)->items()->count());
        $this->assertSame(0, ShoppingList::findOrFail($a)->recipes()->count());
        $this->postJson("/api/shopping-lists/$a/items", ['name' => 'Bread'])->assertCreated();
        $this->postJson("/api/shopping-lists/$a/recipes/$recipe->id")->assertCreated()->assertJsonPath('data.id', $a);
        $this->assertSame(2, ShoppingList::findOrFail($b)->items()->count());
        $this->assertDatabaseHas('shopping_list_items', ['id' => $manual, 'shopping_list_id' => $b, 'is_checked' => true]);
        $this->patchJson("/api/shopping-lists/$a/items/$manual", ['is_checked' => false])->assertNotFound();
        $this->postJson('/api/shopping-lists/active/items', ['name' => 'Ambiguous'])->assertConflict();
        $this->postJson('/api/shopping-lists')->assertOk()->assertJsonPath('data.id', $a);

        Sanctum::actingAs($alina);
        $this->postJson("/api/shopping-lists/$b/items", ['name' => 'Eggs'])->assertCreated();
        $this->deleteJson("/api/shopping-lists/$b/recipes/$recipe->id")->assertOk()->assertJsonCount(0, 'data.recipes');
        $this->assertSame(1, ShoppingList::findOrFail($a)->recipes()->count());
        Sanctum::actingAs($daniel);
        $this->postJson("/api/shopping-lists/$b/close")->assertForbidden();
        $this->postJson("/api/shopping-lists/$a/close")->assertOk()->assertJsonPath('data.status', 'closed');
        $this->getJson('/api/shopping-lists/open')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $b);
    }

    public function test_shared_list_does_not_prevent_creating_exactly_one_owned_open_list(): void
    {
        $daniel = $this->user('Daniel');
        $alina = $this->user('Alina');
        $shared = ShoppingList::create(['created_by' => $alina->id, 'visibility' => 'shared', 'status' => 'open']);
        $shared->users()->attach($daniel->id);
        Sanctum::actingAs($daniel);
        $own = $this->postJson('/api/shopping-lists')->assertCreated()->json('data.id');
        foreach (range(1, 3) as $attempt) {
            $this->postJson('/api/shopping-lists')->assertOk()->assertJsonPath('data.id', $own);
        }
        $this->assertNotSame($shared->id, $own);
        $this->assertSame(1, ShoppingList::where('created_by', $daniel->id)->where('status', 'open')->count());
        $this->assertDatabaseCount('shopping_lists', 2);
    }

    public function test_explicit_mutations_enforce_access_and_open_status(): void
    {
        $owner = $this->user('Owner');
        $member = $this->user('Member');
        $other = $this->user('Other');
        $list = ShoppingList::create(['created_by' => $owner->id, 'visibility' => 'shared', 'status' => 'open']);
        $list->users()->attach($member->id);
        $item = $list->items()->create(['name' => 'Milk']);
        $recipe = $owner->recipes()->create(['name' => 'Recipe']);
        $list->recipes()->attach($recipe->id, ['added_by' => $owner->id]);
        foreach ([[$other, 'shared', 'open'], [$member, 'private', 'open'], [$owner, 'shared', 'closed'], [$member, 'public', 'closed']] as [$user, $visibility, $status]) {
            $list->update(compact('visibility', 'status'));
            Sanctum::actingAs($user);
            $this->postJson("/api/shopping-lists/$list->id/items", ['name' => 'Denied'])->assertForbidden();
            $this->patchJson("/api/shopping-lists/$list->id/items/$item->id", ['is_checked' => true])->assertForbidden();
            $this->postJson("/api/shopping-lists/$list->id/recipes/$recipe->id")->assertForbidden();
            $this->deleteJson("/api/shopping-lists/$list->id/recipes/$recipe->id")->assertForbidden();
        }
        $this->assertDatabaseCount('shopping_list_items', 1);
        $this->assertFalse($item->refresh()->is_checked);
        $list->update(['visibility' => 'public', 'status' => 'open']);
        Sanctum::actingAs($other);
        $this->postJson("/api/shopping-lists/$list->id/items", ['name' => 'Allowed'])->assertCreated();
        $this->patchJson("/api/shopping-lists/$list->id/items/$item->id", ['is_checked' => true])->assertOk();
    }

    public function test_shareable_users_expose_only_id_and_name_and_exclude_requester(): void
    {
        $daniel = $this->user('Daniel');
        $alina = $this->user('Alina');
        $this->getJson('/api/shopping-lists/shareable-users')->assertUnauthorized();
        $this->getJson('/api/shopping-lists/open')->assertUnauthorized();
        Sanctum::actingAs($daniel);
        $this->getJson('/api/shopping-lists/shareable-users')->assertOk()
            ->assertExactJson(['data' => [['id' => $alina->id, 'name' => 'Alina']]]);
    }

    private function user(string $name): User
    {
        return User::create(['username' => $name, 'pin' => '1234']);
    }
}
