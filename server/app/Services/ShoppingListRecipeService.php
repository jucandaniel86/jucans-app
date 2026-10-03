<?php

namespace App\Services;

use App\Models\Recipe;
use App\Models\ShoppingListItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ShoppingListRecipeService
{
    public function __construct(private readonly ShoppingListService $lists) {}

    public function add(User $user, Recipe $recipe): array
    {
        return DB::transaction(function () use ($user, $recipe): array {
            $user = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            // The list lock also serializes additions made by different shared-list members.
            $list = $this->lists->activeListQuery($user)->lockForUpdate()->first();
            $alreadyPresent = $list !== null && $list->recipes()->whereKey($recipe->id)->exists();

            if (! $alreadyPresent) {
                $rows = $recipe->recipeIngredients()->with('ingredient')->lockForUpdate()->get();
                $errors = [];

                foreach ($rows as $row) {
                    $key = 'recipe_ingredients.'.$row->id;
                    if ($row->needs_review) {
                        $errors[$key.'.needs_review'] = "Ingredient {$row->ingredient_id} ({$row->ingredient->name}) requires review.";
                    } elseif ($row->ingredient->is_shoppable && $this->normalizeUnit($row->unit) !== $this->normalizeUnit($row->ingredient->default_unit)) {
                        $errors[$key.'.unit'] = "Ingredient {$row->ingredient_id} ({$row->ingredient->name}) does not use its canonical unit.";
                    }
                }

                if ($errors !== []) {
                    throw ValidationException::withMessages($errors);
                }

                if ($list === null) {
                    [$list] = $this->lists->getOrCreateActive($user);
                }

                // Recheck after acquiring the creation lock: another request may have added it.
                $alreadyPresent = $list->recipes()->whereKey($recipe->id)->exists();
                if (! $alreadyPresent) {
                    $list->recipes()->attach($recipe->id, ['added_by' => $user->id]);

                    foreach ($rows as $row) {
                        $ingredient = $row->ingredient;
                        if (! $ingredient->is_shoppable) {
                            continue;
                        }

                        $item = $list->items()->firstOrCreate(['ingredient_id' => $ingredient->id], [
                            'name' => $ingredient->name,
                            'unit' => $ingredient->default_unit,
                            'shopping_category_id' => $ingredient->shopping_category_id,
                            'is_checked' => false,
                            'quantity_overridden' => false,
                        ]);

                        if ($this->normalizeUnit($item->unit) !== $this->normalizeUnit($ingredient->default_unit)
                            || $item->sources()->get()->contains(fn ($source) => $this->normalizeUnit($source->unit) !== $this->normalizeUnit($ingredient->default_unit))) {
                            throw ValidationException::withMessages([
                                'recipe_ingredients.'.$row->id.'.unit' => "Existing shopping contributions for ingredient {$ingredient->id} do not use its canonical unit.",
                            ]);
                        }

                        $item->sources()->firstOrCreate(['recipe_id' => $recipe->id], [
                            'quantity' => $row->value,
                            'unit' => $ingredient->default_unit,
                        ]);
                        $this->recalculateQuantity($item);
                    }
                }
            }

            return [
                'list' => $this->lists->loadSummaryCounts($list),
                'recipe' => $recipe,
                'already_present' => $alreadyPresent,
                'items' => $list->items()->whereHas('sources', fn ($query) => $query->where('recipe_id', $recipe->id))
                    ->with(['shoppingCategory', 'sources'])->orderBy('id')->get(),
            ];
        });
    }

    public function recalculateQuantity(ShoppingListItem $item): void
    {
        $total = $item->sources()->selectRaw('CASE WHEN COUNT(*) = COUNT(quantity) THEN SUM(quantity) ELSE NULL END AS total')->first()->total;
        $item->calculated_quantity = $total;
        if (! $item->quantity_overridden) {
            $item->quantity = $total;
        }
        $item->save();
    }

    private function normalizeUnit(?string $unit): ?string
    {
        return in_array($unit, [null, '', 'none'], true) ? null : $unit;
    }
}
