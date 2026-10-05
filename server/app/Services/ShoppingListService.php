<?php

namespace App\Services;

use App\Models\ShoppingList;
use App\Models\ShoppingListItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ShoppingListService
{
    public function activeListQuery(User $user): Builder
    {
        return $this->accessibleListQuery($user)->where('status', 'open')->orderByDesc('id');
    }

    public function accessibleListQuery(User $user): Builder
    {
        return ShoppingList::query()->accessibleTo($user);
    }

    public function resolveActiveList(User $user, bool $lock = false): ?ShoppingList
    {
        $query = $this->activeListQuery($user);
        if ($lock) {
            $query->lockForUpdate();
        }
        $candidates = $query->limit(2)->get();
        // Legacy active routes cannot choose safely without explicit selection.
        abort_if($candidates->count() > 1, 409, 'Multiple accessible open shopping lists exist. Explicit list selection is required.');

        return $candidates->first();
    }

    public function summaryQuery(User $user): Builder
    {
        return $this->accessibleListQuery($user)->withCount($this->summaryCounts())
            ->with('creator:id,username,avatar')->withExists($this->membershipCount($user));
    }

    public function getOrCreateActive(User $user): array
    {
        return DB::transaction(function () use ($user): array {
            $user = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $existingList = $this->resolveActiveList($user, true);

            if ($existingList !== null) {
                return [$existingList, false];
            }

            $list = $user->createdShoppingLists()->create([
                'status' => 'open',
                'visibility' => 'private',
            ]);

            return [$list, true];
        });
    }

    public function loadSummaryCounts(ShoppingList $list, User $user): ShoppingList
    {
        return $list->loadCount($this->summaryCounts())
            ->loadMissing('creator:id,username,avatar')->loadExists($this->membershipCount($user));
    }

    private function membershipCount(User $user): array
    {
        return ['users as shared_with_authenticated_user' => fn (Builder $query) => $query->whereKey($user->id)];
    }

    private function summaryCounts(): array
    {
        return [
            'recipes',
            'items',
            'items as unchecked_items_count' => fn (Builder $query) => $query->where('is_checked', false),
        ];
    }

    public function closeActive(User $user): ?ShoppingList
    {
        return DB::transaction(function () use ($user): ?ShoppingList {
            $user = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $list = $this->resolveActiveList($user, true);
            if ($list === null) {
                return null;
            }
            Gate::forUser($user)->authorize('close', $list);
            $list->update(['status' => 'closed', 'closed_at' => now()]);

            return $this->loadSummaryCounts($list, $user);
        });
    }

    public function updateItemChecked(User $user, ShoppingListItem $item, bool $checked): ShoppingListItem
    {
        return DB::transaction(function () use ($user, $item, $checked): ShoppingListItem {
            $list = $this->resolveActiveList($user, true);
            abort_if($list === null, 404);
            Gate::forUser($user)->authorize('update', $list);
            $item = $list->items()->whereKey($item->id)->lockForUpdate()->firstOrFail();

            DB::table('shopping_list_items')->where('id', $item->id)->update(['is_checked' => $checked]);

            return $item->refresh()->load('shoppingCategory');
        });
    }

    public function addManualItem(User $user, array $attributes): ShoppingListItem
    {
        return DB::transaction(function () use ($user, $attributes): ShoppingListItem {
            $list = $this->resolveActiveList($user, true);
            abort_if($list === null, 404);
            Gate::forUser($user)->authorize('update', $list);

            return $list->items()->create([
                'ingredient_id' => null,
                'name' => $attributes['name'],
                'calculated_quantity' => null,
                'quantity' => $attributes['quantity'] ?? null,
                'unit' => $attributes['unit'] ?? null,
                'shopping_category_id' => null,
                'is_checked' => false,
                'quantity_overridden' => true,
            ])->load('shoppingCategory');
        });
    }

    public function changeVisibility(User $user, ShoppingList $list, string $visibility): ShoppingList
    {
        return DB::transaction(function () use ($user, $list, $visibility): ShoppingList {
            $list = ShoppingList::query()->whereKey($list->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($user)->authorize('changeVisibility', $list);
            $list->update(['visibility' => $visibility]);

            return $this->loadSummaryCounts($list, $user);
        });
    }

    public function attachUser(User $creator, ShoppingList $list, User $member): bool
    {
        return DB::transaction(function () use ($creator, $list, $member): bool {
            $list = ShoppingList::query()->whereKey($list->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($creator)->authorize('manageSharing', $list);
            if ((int) $member->id === (int) $list->created_by) {
                throw ValidationException::withMessages(['user_id' => 'The creator cannot be attached to their own list.']);
            }
            $changes = $list->users()->syncWithoutDetaching([$member->id]);

            return $changes['attached'] !== [];
        });
    }

    public function detachUser(User $creator, ShoppingList $list, User $member): void
    {
        DB::transaction(function () use ($creator, $list, $member): void {
            $list = ShoppingList::query()->whereKey($list->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($creator)->authorize('manageSharing', $list);
            $list->users()->detach($member->id);
        });
    }
}
