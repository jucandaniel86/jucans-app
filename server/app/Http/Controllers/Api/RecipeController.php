<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Recipe\ListRecipesRequest;
use App\Http\Requests\Recipe\StoreRecipeRequest;
use App\Http\Requests\Recipe\UpdateRecipeRequest;
use App\Http\Resources\RecipeResource;
use App\Models\Ingredient;
use App\Models\Recipe;
use App\Services\RecipeImageStorage;
use App\Support\NameNormalizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class RecipeController extends Controller
{
    public function index(ListRecipesRequest $request): AnonymousResourceCollection|RecipeResource|JsonResponse
    {
        $filters = $request->validated();
        $query = Recipe::query()->with(['creator', 'tags']);

        if (isset($filters['search'])) {
            $search = $filters['search'];
            $normalizedSearch = NameNormalizer::normalize($search);
            $query->where(function ($query) use ($search, $normalizedSearch): void {
                $query
                    ->where('name', 'like', '%'.$this->escapeLike($search).'%')
                    ->orWhereHas('ingredients', function ($query) use ($normalizedSearch): void {
                        $query->where(
                            'ingredients.normalized_name',
                            'like',
                            '%'.$this->escapeLike($normalizedSearch).'%'
                        );
                    });
            });
        }

        foreach ($filters['tags'] ?? [] as $tagId) {
            $query->whereHas('tags', fn ($query) => $query->whereKey($tagId));
        }

        if ($filters['random']) {
            $recipe = isset($filters['exclude'])
                ? (clone $query)->whereKeyNot($filters['exclude'])->inRandomOrder()->first()
                : $query->inRandomOrder()->first();

            if ($recipe === null && isset($filters['exclude'])) {
                $recipe = $query->inRandomOrder()->first();
            }

            return $recipe === null
                ? response()->json(['data' => null])
                : new RecipeResource($recipe);
        }

        if (($filters['sort'] ?? 'newest') === 'name') {
            $query->orderBy('name')->orderBy('id');
        } else {
            $query->latest()->orderByDesc('id');
        }

        return RecipeResource::collection(
            $query->paginate($filters['per_page'] ?? 20)->withQueryString()
        );
    }

    public function store(
        StoreRecipeRequest $request,
        RecipeImageStorage $imageStorage
    ): RecipeResource {
        $validated = $request->validated();
        $imagePath = $request->hasFile('image')
            ? $imageStorage->store($request->file('image'))
            : null;

        try {
            $recipe = DB::transaction(function () use ($request, $validated, $imagePath): Recipe {
                $attributes = Arr::only($validated, ['name', 'description', 'url']);
                $attributes['image'] = $imagePath;
                $recipe = $request->user()->recipes()->create($attributes);

                $recipe->tags()->sync($validated['tags'] ?? []);
                $recipe->recipeIngredients()->createMany(
                    $this->prepareIngredients($validated['ingredients'] ?? [])
                );

                return $recipe;
            });
        } catch (Throwable $exception) {
            $imageStorage->deleteOwned($imagePath);

            throw $exception;
        }

        return new RecipeResource($this->loadRecipe($recipe));
    }

    public function show(Recipe $recipe): RecipeResource
    {
        return new RecipeResource($this->loadRecipe($recipe));
    }

    public function update(
        UpdateRecipeRequest $request,
        Recipe $recipe,
        RecipeImageStorage $imageStorage
    ): RecipeResource {
        $validated = $request->validated();
        $oldImagePath = $recipe->image;
        $newImagePath = $request->hasFile('image')
            ? $imageStorage->store($request->file('image'))
            : null;
        $removeImage = $request->boolean('remove_image');

        try {
            DB::transaction(function () use ($recipe, $validated, $newImagePath, $removeImage): void {
                $attributes = Arr::only($validated, ['name', 'description', 'url']);

                if ($newImagePath !== null) {
                    $attributes['image'] = $newImagePath;
                } elseif ($removeImage) {
                    $attributes['image'] = null;
                }

                $recipe->update($attributes);

                if (array_key_exists('tags', $validated) || ($validated['tags_present'] ?? false)) {
                    $recipe->tags()->sync($validated['tags'] ?? []);
                }

                if (array_key_exists('ingredients', $validated) || ($validated['ingredients_present'] ?? false)) {
                    $preparedIngredients = $this->prepareIngredients($validated['ingredients'] ?? []);
                    $recipe->recipeIngredients()->delete();
                    $recipe->recipeIngredients()->createMany($preparedIngredients);
                }
            });
        } catch (Throwable $exception) {
            $imageStorage->deleteOwned($newImagePath);

            throw $exception;
        }

        if (($newImagePath !== null || $removeImage) && $oldImagePath !== $newImagePath) {
            $imageStorage->deleteOwned($oldImagePath);
        }

        return new RecipeResource($this->loadRecipe($recipe));
    }

    public function destroy(Recipe $recipe, RecipeImageStorage $imageStorage): Response
    {
        $imagePath = $recipe->image;
        $recipe->delete();
        $imageStorage->deleteOwned($imagePath);

        return response()->noContent();
    }

    private function loadRecipe(Recipe $recipe): Recipe
    {
        return $recipe->load(['creator', 'tags', 'recipeIngredients.ingredient']);
    }

    /**
     * @param  array<int, array<string, mixed>>  $ingredients
     * @return array<int, array<string, mixed>>
     */
    private function prepareIngredients(array $ingredients): array
    {
        $prepared = [];
        $ingredientIds = [];

        foreach ($ingredients as $index => $ingredientData) {
            if (isset($ingredientData['ingredient_id'])) {
                $ingredient = Ingredient::findOrFail($ingredientData['ingredient_id']);
            } else {
                $name = trim($ingredientData['name']);
                $ingredient = Ingredient::query()->firstOrCreate(
                    ['normalized_name' => NameNormalizer::normalize($name)],
                    [
                        'name' => $name,
                        'default_unit' => $ingredientData['default_unit'] ?? null,
                    ]
                );
            }

            if (in_array($ingredient->id, $ingredientIds, true)) {
                throw ValidationException::withMessages([
                    "ingredients.{$index}" => 'Each ingredient may only appear once in a recipe.',
                ]);
            }

            $ingredientIds[] = $ingredient->id;
            $unit = $ingredientData['unit'] ?? null;
            $prepared[] = [
                'ingredient_id' => $ingredient->id,
                'value' => $unit === 'to_taste' ? null : ($ingredientData['value'] ?? null),
                'unit' => $unit,
                'raw_text' => $ingredientData['raw_text'] ?? null,
                'needs_review' => $unit !== $ingredient->default_unit,
            ];
        }

        return $prepared;
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
