<?php

namespace App\Services;

use App\Models\Ingredient;
use App\Models\IngredientAlias;
use App\Models\Recipe;

class IngredientMergeAnalyzer
{
    public function analyze(
        Ingredient $source,
        Ingredient $target,
        bool $lockForUpdate = false
    ): IngredientMergeAnalysis {
        $recipeIngredientQuery = $source->recipeIngredients();

        if ($lockForUpdate) {
            $recipeIngredientQuery->lockForUpdate();
        }

        $recipeIngredients = $recipeIngredientQuery->get(['id', 'recipe_id', 'unit', 'needs_review']);
        $sourceRecipeIds = $recipeIngredients->pluck('recipe_id')->unique()->values();
        $conflicts = Recipe::query()
            ->whereIn('id', $sourceRecipeIds)
            ->whereHas(
                'recipeIngredients',
                fn ($query) => $query->where('ingredient_id', $target->id)
            )
            ->orderBy('name')
            ->orderBy('id')
            ->get(['id', 'name']);
        $canonicalCollision = Ingredient::query()
            ->where('normalized_name', $source->normalized_name)
            ->where($source->getKeyName(), '!=', $source->getKey())
            ->first(['id', 'name']);
        $aliasCollision = IngredientAlias::query()
            ->with('ingredient:id,name')
            ->where('normalized_alias', $source->normalized_name)
            ->whereNotIn('ingredient_id', [$source->id, $target->id])
            ->first(['id', 'ingredient_id', 'alias']);

        return new IngredientMergeAnalysis(
            source: $source,
            target: $target,
            recipeIngredients: $recipeIngredients,
            conflicts: $conflicts,
            aliasCount: $source->aliases()->count(),
            canonicalCollision: $canonicalCollision,
            aliasCollision: $aliasCollision,
        );
    }
}
