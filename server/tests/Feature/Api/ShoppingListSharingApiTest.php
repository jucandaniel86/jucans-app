<?php

namespace Tests\Feature\Api;

use App\Models\Ingredient;
use App\Models\ShoppingList;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ShoppingListSharingApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_private_list_is_visible_and_modifiable_only_by_creator_even_with_retained_membership(): void
    {
        $owner = $this->user('owner');
        $member = $this->user('member');
        $list = $this->list($owner);
        $list->users()->attach($member->id);
        $item = $list->items()->create(['name' => 'Milk']);

        Sanctum::actingAs($owner);
        $this->getJson('/api/shopping-lists/'.$list->id)->assertOk()
            ->assertJsonPath('data.creator.name', 'owner')->assertJsonPath('data.is_creator', true)
            ->assertJsonPath('data.is_shared_with_me', false);
        $this->patchJson('/api/shopping-lists/active/items/'.$item->id, ['is_checked' => true])->assertOk();
        $this->postJson('/api/shopping-lists/active/items', ['name' => 'Dero'])->assertCreated();

        Sanctum::actingAs($member);
        $this->getJson('/api/shopping-lists/'.$list->id)->assertNotFound();
        $this->getJson('/api/shopping-lists')->assertJsonCount(0, 'data');
        $this->patchJson('/api/shopping-lists/active/items/'.$item->id, ['is_checked' => false])->assertNotFound();
        $this->postJson('/api/shopping-lists/active/items', ['name' => 'Denied'])->assertNotFound();
        $this->assertFalse(Gate::forUser($member)->allows('update', $list));
        $this->assertTrue($item->refresh()->is_checked);
        $this->assertSame(2, $list->items()->count());
    }

    public function test_shared_member_can_view_and_modify_but_unrelated_user_cannot_access(): void
    {
        $owner = $this->user('owner');
        $member = $this->user('member');
        $unrelated = $this->user('unrelated');
        $list = $this->list($owner, ['visibility' => 'shared']);
        $list->users()->attach($member->id);
        $item = $list->items()->create(['name' => 'Milk']);
        Sanctum::actingAs($member);

        $this->getJson('/api/shopping-lists/'.$list->id)->assertOk()
            ->assertJsonPath('data.is_creator', false)->assertJsonPath('data.is_shared_with_me', true);
        $this->assertTrue(Gate::forUser($member)->allows('update', $list));
        $this->patchJson('/api/shopping-lists/active/items/'.$item->id, ['is_checked' => true])->assertOk();
        $this->postJson('/api/shopping-lists/active/items', ['name' => 'Dero', 'quantity' => 1, 'unit' => 'buc'])->assertCreated();
        $this->addRecipeTo($owner, $list);
        $this->postJson('/api/shopping-lists/active/close')->assertForbidden();

        Sanctum::actingAs($unrelated);
        $this->getJson('/api/shopping-lists/'.$list->id)->assertNotFound();
        $this->getJson('/api/shopping-lists')->assertJsonCount(0, 'data');
        $this->postJson('/api/shopping-lists/active/items', ['name' => 'Denied'])->assertNotFound();
        $this->patchJson('/api/shopping-lists/active/items/'.$item->id, ['is_checked' => false])->assertNotFound();
        $this->assertFalse(Gate::forUser($unrelated)->allows('update', $list));
    }

    public function test_public_list_can_be_viewed_and_modified_by_other_authenticated_users_without_membership(): void
    {
        $owner = $this->user('owner');
        $other = $this->user('other');
        $list = $this->list($owner, ['visibility' => 'public']);
        $item = $list->items()->create(['name' => 'Milk']);
        Sanctum::actingAs($other);
        $this->getJson('/api/shopping-lists/'.$list->id)->assertOk()->assertJsonPath('data.is_shared_with_me', false);
        $this->getJson('/api/shopping-lists')->assertJsonPath('data.0.id', $list->id);
        $this->assertTrue(Gate::forUser($other)->allows('update', $list));
        $this->postJson('/api/shopping-lists/active/items', ['name' => 'Dero'])->assertCreated();
        $this->patchJson('/api/shopping-lists/active/items/'.$item->id, ['is_checked' => true])->assertOk();
        $this->addRecipeTo($owner, $list);
        $this->postJson('/api/shopping-lists/active/close')->assertForbidden();
        $this->assertDatabaseCount('shopping_list_users', 0);
    }

    public function test_visibility_changes_preserve_memberships_and_toggle_effective_access(): void
    {
        $owner = $this->user('owner');
        $member = $this->user('member');
        $list = $this->list($owner, ['visibility' => 'shared']);
        $list->users()->attach($member->id);
        Sanctum::actingAs($owner);
        $this->patchJson($this->visibilityUrl($list), ['visibility' => 'private'])->assertOk()
            ->assertJsonPath('data.visibility', 'private')->assertJsonPath('data.creator.id', $owner->id);
        $this->assertDatabaseHas('shopping_list_users', ['shopping_list_id' => $list->id, 'user_id' => $member->id]);
        Sanctum::actingAs($member);
        $this->getJson('/api/shopping-lists/'.$list->id)->assertNotFound();
        $this->postJson('/api/shopping-lists/active/items', ['name' => 'Denied'])->assertNotFound();
        Sanctum::actingAs($owner);
        $this->patchJson($this->visibilityUrl($list), ['visibility' => 'shared'])->assertOk();
        Sanctum::actingAs($member);
        $this->getJson('/api/shopping-lists/'.$list->id)->assertOk()->assertJsonPath('data.is_shared_with_me', true);
        $this->postJson('/api/shopping-lists/active/items', ['name' => 'Restored'])->assertCreated();
        Sanctum::actingAs($owner);
        $this->patchJson($this->visibilityUrl($list), ['visibility' => 'public'])->assertOk();
        $this->assertDatabaseCount('shopping_list_users', 1);
    }

    public function test_visibility_is_strictly_validated_and_non_creators_cannot_change_it(): void
    {
        $owner = $this->user('owner');
        $member = $this->user('member');
        $list = $this->list($owner, ['visibility' => 'shared']);
        $list->users()->attach($member->id);
        Sanctum::actingAs($owner);
        foreach ([[], ['visibility' => null], ['visibility' => 'friends'], ['visibility' => 'PUBLIC'], ['visibility' => ['public']]] as $payload) {
            $this->patchJson($this->visibilityUrl($list), $payload)->assertUnprocessable()->assertJsonValidationErrors('visibility');
        }
        Sanctum::actingAs($member);
        $this->patchJson($this->visibilityUrl($list), ['visibility' => 'public'])->assertForbidden();
        Sanctum::actingAs($this->user('unrelated'));
        $this->patchJson($this->visibilityUrl($list), ['visibility' => 'public'])->assertForbidden();
        $this->assertSame('shared', $list->refresh()->visibility);
    }

    public function test_creator_can_list_attach_and_detach_existing_users_idempotently_without_changing_visibility(): void
    {
        $owner = $this->user('owner');
        $member = $this->user('member');
        $list = $this->list($owner);
        Sanctum::actingAs($owner);
        $this->postJson($this->usersUrl($list), ['user_id' => $member->id])->assertCreated()
            ->assertJsonPath('data.id', $member->id)->assertJsonPath('data.name', 'member')
            ->assertJsonMissingPath('data.pin')->assertJsonMissingPath('data.is_admin');
        $this->postJson($this->usersUrl($list), ['user_id' => $member->id])->assertOk();
        $this->assertDatabaseCount('shopping_list_users', 1);
        $this->assertSame('private', $list->refresh()->visibility);
        $this->getJson($this->usersUrl($list))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $member->id);
        $this->patchJson($this->visibilityUrl($list), ['visibility' => 'shared'])->assertOk();
        Sanctum::actingAs($member);
        $this->getJson('/api/shopping-lists/'.$list->id)->assertOk();
        Sanctum::actingAs($owner);
        $this->deleteJson($this->usersUrl($list).'/'.$member->id)->assertNoContent();
        $this->deleteJson($this->usersUrl($list).'/'.$member->id)->assertNoContent();
        Sanctum::actingAs($member);
        $this->getJson('/api/shopping-lists/'.$list->id)->assertNotFound();
        $this->postJson('/api/shopping-lists/active/items', ['name' => 'Denied'])->assertNotFound();
        $this->assertDatabaseCount('shopping_list_users', 0);
    }

    public function test_creator_cannot_attach_themselves_or_nonexistent_users(): void
    {
        $owner = $this->user('owner');
        $list = $this->list($owner);
        Sanctum::actingAs($owner);
        foreach ([[], ['user_id' => $owner->id], ['user_id' => 999999], ['user_id' => 'invalid']] as $payload) {
            $this->postJson($this->usersUrl($list), $payload)->assertUnprocessable()->assertJsonValidationErrors('user_id');
        }
        $this->assertDatabaseCount('shopping_list_users', 0);
    }

    public function test_sharing_management_is_creator_only_even_for_shared_members_and_public_users(): void
    {
        $owner = $this->user('owner');
        $member = $this->user('member');
        $other = $this->user('other');
        $list = $this->list($owner, ['visibility' => 'shared']);
        $list->users()->attach($member->id);
        foreach (['shared', 'public'] as $visibility) {
            $list->update(['visibility' => $visibility]);
            foreach ([$member, $other] as $user) {
                Sanctum::actingAs($user);
                $this->getJson($this->usersUrl($list))->assertForbidden();
                $this->postJson($this->usersUrl($list), ['user_id' => $other->id])->assertForbidden();
                $this->deleteJson($this->usersUrl($list).'/'.$member->id)->assertForbidden();
                $this->patchJson($this->visibilityUrl($list), ['visibility' => 'private'])->assertForbidden();
            }
        }
        $this->assertDatabaseCount('shopping_list_users', 1);
    }

    public function test_own_and_shared_open_lists_coexist_without_arbitrary_active_selection_or_writes(): void
    {
        $daniel = $this->user('daniel');
        $alina = $this->user('alina');
        $own = $this->list($daniel);
        $shared = $this->list($alina, ['visibility' => 'shared']);
        $item = $own->items()->create(['name' => 'Milk']);
        Sanctum::actingAs($alina);
        $this->postJson($this->usersUrl($shared), ['user_id' => $daniel->id])->assertCreated();
        Sanctum::actingAs($daniel);
        $this->getJson('/api/shopping-lists')->assertOk()->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.id', $shared->id)->assertJsonPath('data.0.creator.name', 'alina')
            ->assertJsonPath('data.0.is_creator', false)->assertJsonPath('data.0.is_shared_with_me', true)
            ->assertJsonPath('data.1.id', $own->id)->assertJsonPath('data.1.is_creator', true);
        foreach ([$own, $shared] as $list) {
            $this->getJson('/api/shopping-lists/'.$list->id)->assertOk();
            $this->assertTrue(Gate::forUser($daniel)->allows('update', $list));
        }
        $recipe = $daniel->recipes()->create(['name' => 'Recipe']);
        $this->getJson('/api/shopping-lists/active')->assertConflict();
        $this->postJson('/api/shopping-lists')->assertOk()->assertJsonPath('data.id', $own->id);
        $this->postJson('/api/shopping-lists/active/items', ['name' => 'Must not add'])->assertConflict();
        $this->patchJson('/api/shopping-lists/active/items/'.$item->id, ['is_checked' => true])->assertConflict();
        $this->postJson('/api/shopping-lists/active/recipes/'.$recipe->id)->assertConflict();
        $this->postJson('/api/shopping-lists/active/close')->assertConflict();
        $this->assertDatabaseCount('shopping_lists', 2);
        $this->assertDatabaseCount('shopping_list_items', 1);
        $this->assertDatabaseCount('shopping_list_recipes', 0);
        $this->assertFalse($item->refresh()->is_checked);
        $this->assertSame('open', $own->refresh()->status);
        $this->assertSame('open', $shared->refresh()->status);
        Sanctum::actingAs($alina);
        $this->getJson('/api/shopping-lists/active')->assertOk()->assertJsonPath('data.id', $shared->id);
    }

    public function test_index_includes_accessible_history_and_has_no_duplicates_when_multiple_access_conditions_match(): void
    {
        $owner = $this->user('owner');
        $viewer = $this->user('viewer');
        $public = $this->list($owner, ['visibility' => 'public', 'status' => 'closed', 'closed_at' => now()]);
        $public->users()->attach($viewer->id);
        $shared = $this->list($owner, ['visibility' => 'shared', 'status' => 'closed', 'closed_at' => now()]);
        $shared->users()->attach($viewer->id);
        $private = $this->list($owner, ['status' => 'closed', 'closed_at' => now()]);
        $private->users()->attach($viewer->id);
        $own = $this->list($viewer);
        $own->users()->attach($viewer->id);
        Sanctum::actingAs($viewer);
        $response = $this->getJson('/api/shopping-lists')->assertOk()->assertJsonPath('meta.total', 3)
            ->assertJsonCount(3, 'data')->assertJsonMissingPath('data.0.creator.pin')
            ->assertJsonMissingPath('data.0.creator.is_admin');
        $this->assertEqualsCanonicalizing([$own->id, $public->id, $shared->id], array_column($response->json('data'), 'id'));
        $this->assertFalse(Gate::forUser($viewer)->allows('update', $shared));
        $this->assertFalse(Gate::forUser($viewer)->allows('update', $public));
        $this->getJson('/api/shopping-lists/active')->assertJsonPath('data.id', $own->id);
    }

    public function test_sharing_endpoints_require_authentication(): void
    {
        $owner = $this->user('owner');
        $member = $this->user('member');
        $list = $this->list($owner);
        $this->getJson($this->usersUrl($list))->assertUnauthorized();
        $this->postJson($this->usersUrl($list), ['user_id' => $member->id])->assertUnauthorized();
        $this->deleteJson($this->usersUrl($list).'/'.$member->id)->assertUnauthorized();
        $this->patchJson($this->visibilityUrl($list), ['visibility' => 'public'])->assertUnauthorized();
    }

    private function addRecipeTo(User $owner, ShoppingList $list): void
    {
        $ingredient = Ingredient::create(['name' => 'Flour', 'default_unit' => 'gram', 'is_shoppable' => true]);
        $recipe = $owner->recipes()->create(['name' => 'Bread']);
        $recipe->recipeIngredients()->create(['ingredient_id' => $ingredient->id, 'value' => 100, 'unit' => 'gram', 'needs_review' => false]);
        $this->postJson('/api/shopping-lists/active/recipes/'.$recipe->id)->assertCreated()
            ->assertJsonPath('data.id', $list->id)->assertJsonPath('data.creator.id', $owner->id)
            ->assertJsonPath('data.items.0.quantity', '100.000');
    }

    private function user(string $username): User
    {
        return User::create(['username' => $username, 'avatar' => 'D', 'pin' => '1234']);
    }

    private function list(User $owner, array $attributes = []): ShoppingList
    {
        return ShoppingList::create(['created_by' => $owner->id, 'status' => 'open', 'visibility' => 'private', ...$attributes]);
    }

    private function usersUrl(ShoppingList $list): string
    {
        return '/api/shopping-lists/'.$list->id.'/users';
    }

    private function visibilityUrl(ShoppingList $list): string
    {
        return '/api/shopping-lists/'.$list->id.'/visibility';
    }
}
