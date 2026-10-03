<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ListRecipeIngredientReviewsRequest;
use App\Http\Resources\RecipeIngredientReviewResource;
use App\Models\RecipeIngredient;
use App\Support\NameNormalizer;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class RecipeIngredientReviewController extends Controller
{
    public function index(ListRecipeIngredientReviewsRequest $request): AnonymousResourceCollection
    {
        $validated = $request->validated();
        $search = $validated['search'] ?? null;

        $reviews = RecipeIngredient::query()
            ->select('recipe_ingredients.*')
            ->with(['recipe:id,name', 'ingredient:id,name,default_unit'])
            ->join('ingredients', 'ingredients.id', '=', 'recipe_ingredients.ingredient_id')
            ->join('recipes', 'recipes.id', '=', 'recipe_ingredients.recipe_id')
            ->where('recipe_ingredients.needs_review', true)
            ->when($search, function ($query, string $search): void {
                $rawPattern = '%'.$this->escapeLike($search).'%';
                $normalizedPattern = '%'.$this->escapeLike(NameNormalizer::normalize($search)).'%';

                $query->where(function ($query) use ($rawPattern, $normalizedPattern): void {
                    $query
                        ->where('ingredients.normalized_name', 'like', $normalizedPattern)
                        ->orWhere('recipes.name', 'like', $rawPattern)
                        ->orWhere('recipe_ingredients.raw_text', 'like', $rawPattern);
                });
            })
            ->orderBy('ingredients.normalized_name')
            ->orderBy('recipes.name')
            ->orderBy('recipe_ingredients.id')
            ->paginate($validated['per_page'] ?? 20)
            ->withQueryString();

        return RecipeIngredientReviewResource::collection($reviews);
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
