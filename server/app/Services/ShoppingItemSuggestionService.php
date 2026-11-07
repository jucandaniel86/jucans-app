<?php

namespace App\Services;

use App\Http\Resources\ShoppingCategoryResource;
use App\Models\Ingredient;
use App\Models\ShoppingList;
use App\Models\ShoppingListItem;
use App\Models\User;
use App\Support\NameNormalizer;
use Illuminate\Support\Facades\DB;

class ShoppingItemSuggestionService
{
    public function search(User $user, string $query): array
    {
        $term = NameNormalizer::normalize($query);
        if ($term === '') {
            return [];
        }
        // Use ! as the LIKE escape character, independently of MySQL backslash modes.
        $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term);
        $prefix = $escaped.'%';
        $contains = '%'.$escaped.'%';
        $ingredients = Ingredient::query()->with('shoppingCategory')
            ->whereRaw("normalized_name LIKE ? ESCAPE '!'", [$contains])
            ->orderByRaw("CASE WHEN normalized_name LIKE ? ESCAPE '!' THEN 0 ELSE 1 END", [$prefix])
            ->orderBy('normalized_name')->orderBy('id')->limit(8)->get();

        $visibleLists = ShoppingList::query()->accessibleTo($user)->select('id');
        $key = $this->normalizedSql('shopping_list_items.name');
        $timestamp = "COALESCE(shopping_list_items.updated_at, shopping_list_items.created_at, '1000-01-01')";
        $matching = ShoppingListItem::query()
            ->whereIn('shopping_list_id', $visibleLists)
            ->whereRaw("$key LIKE ? ESCAPE '!'", [$contains]);
        // Expose normalization as a derived column before aggregation, so strict
        // GROUP BY never needs to infer a dependency on the original name expression.
        $normalized = (clone $matching)->select('shopping_list_items.id')
            ->selectRaw("$key AS normalized_name, $timestamp AS item_updated_at");
        $latest = DB::query()->fromSub(clone $normalized, 'matched')
            ->select('matched.normalized_name')
            ->selectRaw('MAX(matched.item_updated_at) AS latest_at')
            ->groupBy('matched.normalized_name');
        // Keep the newest timestamp per accessible name, breaking ties by ID.
        $candidates = DB::query()->fromSub(clone $normalized, 'matched')
            ->joinSub($latest, 'latest', function ($join): void {
                $join->on('matched.normalized_name', '=', 'latest.normalized_name')
                    ->on('matched.item_updated_at', '=', 'latest.latest_at');
            })->selectRaw('MAX(matched.id) AS item_id')
            ->groupBy('matched.normalized_name')
            ->orderByRaw("CASE WHEN matched.normalized_name LIKE ? ESCAPE '!' THEN 0 ELSE 1 END", [$prefix])
            ->orderBy('matched.normalized_name')->limit(8);
        $items = ShoppingListItem::query()->with('shoppingCategory')
            ->joinSub($candidates, 'candidates', function ($join): void {
                $join->on('shopping_list_items.id', '=', 'candidates.item_id');
            })->select('shopping_list_items.*')->get();

        // A historical match must still prefer its canonical ingredient, even when
        // that ingredient was outside the first bounded ingredient result set.
        $canonical = $items->isEmpty() ? collect() : Ingredient::query()->with('shoppingCategory')->whereIn('normalized_name',
            $items->map(fn ($item) => NameNormalizer::normalize($item->name))->all())->get();
        $results = [];
        foreach ($items as $item) {
            $results[NameNormalizer::normalize($item->name)] = $this->suggestion($item, 'shopping_list_items');
        }
        foreach ($ingredients->concat($canonical) as $ingredient) {
            $results[NameNormalizer::normalize($ingredient->name)] = $this->suggestion($ingredient, 'ingredients');
        }
        uksort($results, function (string $a, string $b) use ($term): int {
            return ((int) ! str_starts_with($a, $term) <=> (int) ! str_starts_with($b, $term))
                ?: strcmp($a, $b);
        });

        return array_slice(array_values($results), 0, 8);
    }

    private function suggestion(Ingredient|ShoppingListItem $item, string $source): array
    {
        return [
            'name' => $item->name,
            'unit' => $source === 'ingredients' ? $item->default_unit : $item->unit,
            'category' => $item->shoppingCategory === null ? null : new ShoppingCategoryResource($item->shoppingCategory),
            'source' => $source,
        ];
    }

    private function normalizedSql(string $column): string
    {
        // Match NameNormalizer for Romanian product names without a new column or
        // a dependency on MySQL 8 REGEXP_REPLACE. Names are VARCHAR(255).
        // Existing production shopping items use latin1; convert before Unicode replacements.
        $sql = "LOWER(CONVERT($column USING utf8mb4) COLLATE utf8mb4_unicode_ci)";
        foreach (['ă' => 'a', 'â' => 'a', 'î' => 'i', 'ș' => 's', 'ş' => 's', 'ț' => 't', 'ţ' => 't'] as $from => $to) {
            $sql = "REPLACE($sql, '$from', '$to')";
        }
        foreach ([9, 10, 11, 12, 13] as $char) {
            $sql = "REPLACE($sql, CHAR($char), ' ')";
        }
        for ($i = 0; $i < 8; $i++) {
            $sql = "REPLACE($sql, '  ', ' ')";
        }

        return "TRIM($sql)";
    }
}
