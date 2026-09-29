<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Ingredient\StoreIngredientAliasRequest;
use App\Http\Resources\IngredientResource;
use App\Models\Ingredient;
use App\Models\IngredientAlias;
use App\Support\NameNormalizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class IngredientAliasController extends Controller
{
    public function store(StoreIngredientAliasRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $normalizedAlias = NameNormalizer::normalize($validated['alias']);
        $ingredient = Ingredient::findOrFail($validated['ingredient_id']);

        $canonicalConflict = Ingredient::query()
            ->where('normalized_name', $normalizedAlias)
            ->whereKeyNot($ingredient->getKey())
            ->exists();

        if ($canonicalConflict) {
            throw ValidationException::withMessages([
                'alias' => 'This alias is already the canonical name of another ingredient.',
            ]);
        }

        $existingAlias = IngredientAlias::query()
            ->where('normalized_alias', $normalizedAlias)
            ->first();

        if ($existingAlias && $existingAlias->ingredient_id !== $ingredient->id) {
            throw ValidationException::withMessages([
                'alias' => 'This alias is already associated with another ingredient.',
            ]);
        }

        $alias = $existingAlias ?? IngredientAlias::create([
            'ingredient_id' => $ingredient->id,
            'alias' => $validated['alias'],
        ]);

        return response()->json([
            'data' => [
                'id' => $alias->id,
                'alias' => $alias->alias,
                'normalized_alias' => $alias->normalized_alias,
                'ingredient' => (new IngredientResource($ingredient))->resolve(),
            ],
        ], $alias->wasRecentlyCreated ? 201 : 200);
    }
}
