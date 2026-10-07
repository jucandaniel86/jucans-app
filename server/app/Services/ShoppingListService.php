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

    public function getOrCreateOwnedOpen(User $user): array
    {
        return DB::transaction(function () use ($user): array {
            $user = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $existingList = ShoppingList::query()->where('created_by', $user->id)
                ->where('status', 'open')->lockForUpdate()->first();

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
            return $this->closeList($user, $list);
        });
    }

    public function closeList(User $user, ShoppingList $list): ShoppingList
    {
        return DB::transaction(function () use ($user, $list): ShoppingList {
            $list = ShoppingList::query()->whereKey($list->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($user)->authorize('close', $list);
            $list->update(['status' => 'closed', 'closed_at' => now()]);

            return $this->loadSummaryCounts($list, $user);
        });
    }

    public function editableList(User $user, ?ShoppingList $target = null): ShoppingList
    {
        $list = $target === null ? $this->resolveActiveList($user, true)
            : ShoppingList::query()->whereKey($target->id)->lockForUpdate()->firstOrFail();
        abort_if($list === null, 404);
        Gate::forUser($user)->authorize('update', $list);

        return $list;
    }

    public function updateItemChecked(User $user, ShoppingListItem $item, bool $checked, ?ShoppingList $target = null): ShoppingListItem
    {
        return DB::transaction(function () use ($user, $item, $checked, $target): ShoppingListItem {
            $list = $this->editableList($user, $target);
            $item = $list->items()->whereKey($item->id)->lockForUpdate()->firstOrFail();

            DB::table('shopping_list_items')->where('id', $item->id)->update(['is_checked' => $checked]);

            return $item->refresh()->load('shoppingCategory');
        });
    }

    public function updateItem(User $user, ShoppingListItem $item, array $attributes, ?ShoppingList $target = null): ShoppingListItem
    {
        return DB::transaction(function () use ($user, $item, $attributes, $target): ShoppingListItem {
            $list = $this->editableList($user, $target);
            $item = $list->items()->withCount('sources')->whereKey($item->id)->lockForUpdate()->firstOrFail();

            $changes = [];
            if (array_key_exists('is_checked', $attributes)) {
                $changes['is_checked'] = (bool) $attributes['is_checked'];
            }

            if ($this->isManualItem($item)) {
                foreach (['name', 'quantity', 'unit'] as $field) {
                    if (array_key_exists($field, $attributes)) {
                        $changes[$field] = $attributes[$field];
                    }
                }
            } elseif (array_key_exists('reset_quantity', $attributes) && $attributes['reset_quantity']) {
                $changes['quantity'] = $item->calculated_quantity;
                $changes['quantity_overridden'] = false;
            } elseif (array_key_exists('quantity', $attributes)) {
                $changes['quantity'] = $attributes['quantity'];
                $changes['quantity_overridden'] = true;
            }

            abort_if($changes === [], 422, 'This shopping list item cannot be updated with the provided fields.');
            DB::table('shopping_list_items')->where('id', $item->id)->update($changes);

            return $item->refresh()->load(['shoppingCategory', 'sources']);
        });
    }

    public function deleteManualItem(User $user, ShoppingListItem $item, ShoppingList $target): void
    {
        DB::transaction(function () use ($user, $item, $target): void {
            $list = $this->editableList($user, $target);
            $item = $list->items()->withCount('sources')->whereKey($item->id)->lockForUpdate()->firstOrFail();
            abort_unless($this->isManualItem($item), 422, 'Recipe-generated shopping list items cannot be deleted directly.');
            $item->delete();
        });
    }

    public function exportUnchecked(User $user, ShoppingList $target): ?string
    {
        $list = $this->accessibleListQuery($user)->whereKey($target->id)->firstOrFail();
        Gate::forUser($user)->authorize('view', $list);
        $items = $list->items()
            ->with('shoppingCategory')
            ->where('is_checked', false)
            ->get()
            ->sort(function (ShoppingListItem $a, ShoppingListItem $b): int {
                return ($a->shoppingCategory?->sort_order ?? PHP_INT_MAX) <=> ($b->shoppingCategory?->sort_order ?? PHP_INT_MAX)
                    ?: strcoll($a->shoppingCategory?->name ?? 'Diverse', $b->shoppingCategory?->name ?? 'Diverse')
                    ?: strcoll($a->name, $b->name);
            })
            ->values();
        if ($items->isEmpty()) {
            return null;
        }

        $lines = ['LISTA DE CUMPĂRĂTURI', ''];
        $currentCategory = null;
        foreach ($items as $item) {
            $categoryKey = $item->shopping_category_id ?? 0;
            if ($categoryKey !== $currentCategory) {
                if ($currentCategory !== null) {
                    $lines[] = '';
                }
                $currentCategory = $categoryKey;
                $emoji = $item->shoppingCategory?->emoji ?: '📦';
                $name = $item->shoppingCategory?->name ?: 'Diverse';
                $lines[] = trim($emoji.' '.$name);
            }
            $lines[] = $this->exportItemLine($item);
        }

        return implode("\n", $lines)."\n";
    }

    public function addManualItem(User $user, array $attributes, ?ShoppingList $target = null): ShoppingListItem
    {
        return DB::transaction(function () use ($user, $attributes, $target): ShoppingListItem {
            $list = $this->editableList($user, $target);

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

    private function isManualItem(ShoppingListItem $item): bool
    {
        return $item->ingredient_id === null && (int) ($item->sources_count ?? $item->sources()->count()) === 0;
    }

    private function exportItemLine(ShoppingListItem $item): string
    {
        $quantity = $this->exportQuantity($item);

        return $quantity === '' ? $item->name : $item->name.' — '.$quantity;
    }

    private function exportQuantity(ShoppingListItem $item): string
    {
        if ($item->unit === 'to_taste') {
            return 'după gust';
        }

        if ($item->quantity === null) {
            return '';
        }

        $quantity = rtrim(rtrim((string) $item->quantity, '0'), '.');
        $unit = $item->unit;
        if ($unit === null || $unit === 'none') {
            return $quantity;
        }

        return trim($quantity.' '.$unit);
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
