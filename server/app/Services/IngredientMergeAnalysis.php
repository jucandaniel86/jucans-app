<?php

namespace App\Services;

use App\Models\Ingredient;
use App\Models\IngredientAlias;
use App\Models\Recipe;
use App\Models\RecipeIngredient;
use Illuminate\Database\Eloquent\Collection;

class IngredientMergeAnalysis
{
    /**
     * @param  Collection<int, RecipeIngredient>  $recipeIngredients
     * @param  Collection<int, Recipe>  $conflicts
     */
    public function __construct(
        public readonly Ingredient $source,
        public readonly Ingredient $target,
        public readonly Collection $recipeIngredients,
        public readonly Collection $conflicts,
        public readonly int $aliasCount,
        public readonly ?Ingredient $canonicalCollision,
        public readonly ?IngredientAlias $aliasCollision,
    ) {}

    public function needsReviewCount(): int
    {
        return $this->recipeIngredients
            ->filter(fn (RecipeIngredient $row) => $row->unit !== $this->target->default_unit)
            ->count();
    }

    public function hasAliasNameCollision(): bool
    {
        return $this->canonicalCollision !== null || $this->aliasCollision !== null;
    }

    public function canMerge(): bool
    {
        return $this->conflicts->isEmpty() && ! $this->hasAliasNameCollision();
    }

    /**
     * @return array<int, array{recipe_id: int, recipe_name: string}>
     */
    public function conflictsForResponse(): array
    {
        return $this->conflicts
            ->map(fn (Recipe $recipe) => [
                'recipe_id' => $recipe->id,
                'recipe_name' => $recipe->name,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array{
     *     has_collision: bool,
     *     canonical_ingredient: array{id: int, name: string}|null,
     *     existing_alias: array{id: int, alias: string, ingredient_id: int, ingredient_name: string}|null
     * }
     */
    public function aliasNameCollisionForResponse(): array
    {
        return [
            'has_collision' => $this->hasAliasNameCollision(),
            'canonical_ingredient' => $this->canonicalCollision === null ? null : [
                'id' => $this->canonicalCollision->id,
                'name' => $this->canonicalCollision->name,
            ],
            'existing_alias' => $this->aliasCollision === null ? null : [
                'id' => $this->aliasCollision->id,
                'alias' => $this->aliasCollision->alias,
                'ingredient_id' => $this->aliasCollision->ingredient_id,
                'ingredient_name' => $this->aliasCollision->ingredient->name,
            ],
        ];
    }

    public function toPreviewArray(): array
    {
        return [
            'source' => $this->ingredientSummary($this->source),
            'target' => $this->ingredientSummary($this->target),
            'impact' => [
                'recipe_count' => $this->recipeIngredients->pluck('recipe_id')->unique()->count(),
                'recipe_ingredient_count' => $this->recipeIngredients->count(),
                'alias_count' => $this->aliasCount,
                'needs_review_count' => $this->needsReviewCount(),
            ],
            'unit_mismatch' => $this->source->default_unit !== $this->target->default_unit,
            'conflicts' => $this->conflictsForResponse(),
            'alias_name_collision' => $this->aliasNameCollisionForResponse(),
            'can_merge' => $this->canMerge(),
        ];
    }

    /**
     * @return array{id: int, name: string, default_unit: string|null}
     */
    private function ingredientSummary(Ingredient $ingredient): array
    {
        return [
            'id' => $ingredient->id,
            'name' => $ingredient->name,
            'default_unit' => $ingredient->default_unit,
        ];
    }
}
