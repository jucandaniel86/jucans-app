<?php

namespace App\Services;

use App\Models\ShoppingList;
use App\Models\ShoppingListItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class ShoppingListService
{
    public function activeListQuery(User $user): Builder
    {
        return $this->accessibleListQuery($user)->where('status', 'open')->orderByDesc('id');
    }

    public function accessibleListQuery(User $user): Builder
    {
        return ShoppingList::query()
            ->where(function (Builder $query) use ($user): void {
                $query->where('created_by', $user->id)
                    ->orWhereHas('users', fn (Builder $query) => $query->whereKey($user->id));
            });
    }

    public function summaryQuery(User $user): Builder
    {
        return $this->accessibleListQuery($user)->withCount($this->summaryCounts());
    }

    public function getOrCreateActive(User $user): array
    {
        return DB::transaction(function () use ($user): array {
            $user = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $existingList = $this->activeListQuery($user)->lockForUpdate()->first();

            if ($existingList !== null) {
                return [$existingList, false];
            }

            $list = $user->createdShoppingLists()->create([
                'status' => 'open',
                'visibility' => 'private',
            ]);
            $list->users()->attach($user->id);

            return [$list, true];
        });
    }

    public function loadSummaryCounts(ShoppingList $list): ShoppingList
    {
        return $list->loadCount($this->summaryCounts());
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
            $list = $this->activeListQuery($user)->lockForUpdate()->first();
            if ($list === null) {
                return null;
            }
            $list->update(['status' => 'closed', 'closed_at' => now()]);

            return $this->loadSummaryCounts($list);
        });
    }

    public function updateItemChecked(User $user, ShoppingListItem $item, bool $checked): ShoppingListItem
    {
        return DB::transaction(function () use ($user, $item, $checked): ShoppingListItem {
            $list = $this->activeListQuery($user)->lockForUpdate()->firstOrFail();
            $item = $list->items()->whereKey($item->id)->lockForUpdate()->firstOrFail();

            DB::table('shopping_list_items')->where('id', $item->id)->update(['is_checked' => $checked]);

            return $item->refresh()->load('shoppingCategory');
        });
    }
}
