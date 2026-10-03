<?php

namespace App\Services;

use App\Exceptions\IngredientMergeBlockedException;
use App\Models\Ingredient;
use App\Models\IngredientAlias;
use Illuminate\Support\Facades\DB;

class IngredientMerger
{
    public function __construct(private readonly IngredientMergeAnalyzer $analyzer) {}

    /**
     * @return array{
     *     target: Ingredient,
     *     merged_source: array{id: int, name: string},
     *     result: array{recipe_ingredient_count: int, alias_count: int, needs_review_count: int}
     * }
     */
    public function merge(int $sourceId, int $targetId): array
    {
        return DB::transaction(function () use ($sourceId, $targetId): array {
            $ingredients = Ingredient::query()
                ->whereKey([$sourceId, $targetId])
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            $source = $ingredients->get($sourceId) ?? Ingredient::query()->findOrFail($sourceId);
            $target = $ingredients->get($targetId) ?? Ingredient::query()->findOrFail($targetId);
            $analysis = $this->analyzer->analyze($source, $target, true);

            if (! $analysis->canMerge()) {
                throw new IngredientMergeBlockedException($analysis);
            }

            foreach ($analysis->recipeIngredients as $recipeIngredient) {
                $recipeIngredient->ingredient_id = $target->id;

                if ($recipeIngredient->unit !== $target->default_unit) {
                    $recipeIngredient->needs_review = true;
                }

                $recipeIngredient->save();
            }

            $aliasCount = $this->moveAliases($source, $target);
            $mergedSource = [
                'id' => $source->id,
                'name' => $source->name,
            ];
            $source->delete();

            return [
                'target' => $target->refresh()->load(['shoppingCategory', 'aliases']),
                'merged_source' => $mergedSource,
                'result' => [
                    'recipe_ingredient_count' => $analysis->recipeIngredients->count(),
                    'alias_count' => $aliasCount,
                    'needs_review_count' => $analysis->needsReviewCount(),
                ],
            ];
        });
    }

    private function moveAliases(Ingredient $source, Ingredient $target): int
    {
        $handledAliasCount = 0;
        $targetAliases = IngredientAlias::query()
            ->where('ingredient_id', $target->id)
            ->pluck('id', 'normalized_alias');
        $sourceAliases = IngredientAlias::query()
            ->where('ingredient_id', $source->id)
            ->lockForUpdate()
            ->get();

        foreach ($sourceAliases as $alias) {
            if ($targetAliases->has($alias->normalized_alias)) {
                $alias->delete();

                continue;
            }

            $alias->ingredient_id = $target->id;
            $alias->save();
            $targetAliases->put($alias->normalized_alias, $alias->id);
            $handledAliasCount++;
        }

        if (! $targetAliases->has($source->normalized_name)) {
            $sourceNameAlias = $target->aliases()->create(['alias' => $source->name]);
            $targetAliases->put($sourceNameAlias->normalized_alias, $sourceNameAlias->id);
            $handledAliasCount++;
        }

        return $handledAliasCount;
    }
}
