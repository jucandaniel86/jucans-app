<?php

namespace App\Http\Controllers\Api\Admin;

use App\Exceptions\IngredientMergeBlockedException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ListIngredientsRequest;
use App\Http\Requests\Admin\PreviewIngredientMergeRequest;
use App\Http\Requests\Admin\UpdateIngredientRequest;
use App\Http\Resources\AdminIngredientResource;
use App\Models\Ingredient;
use App\Services\IngredientMergeAnalyzer;
use App\Services\IngredientMerger;
use App\Support\NameNormalizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

class AdminIngredientController extends Controller
{
    public function index(ListIngredientsRequest $request): AnonymousResourceCollection
    {
        $validated = $request->validated();
        $search = isset($validated['search'])
            ? NameNormalizer::normalize($validated['search'])
            : null;

        $ingredients = Ingredient::query()
            ->with(['shoppingCategory', 'aliases'])
            ->when($search, function ($query, string $search): void {
                $pattern = '%'.$this->escapeLike($search).'%';

                $query->where(function ($query) use ($pattern): void {
                    $query
                        ->where('normalized_name', 'like', $pattern)
                        ->orWhereHas('aliases', fn ($query) => $query->where('normalized_alias', 'like', $pattern));
                });
            })
            ->when($request->boolean('missing_unit'), fn ($query) => $query->whereNull('default_unit'))
            ->when($request->boolean('missing_category'), fn ($query) => $query->whereNull('shopping_category_id'))
            ->when(
                array_key_exists('is_shoppable', $validated),
                fn ($query) => $query->where('is_shoppable', $request->boolean('is_shoppable'))
            )
            ->orderBy('normalized_name')
            ->paginate($validated['per_page'] ?? 20)
            ->withQueryString();

        return AdminIngredientResource::collection($ingredients);
    }

    public function update(UpdateIngredientRequest $request, Ingredient $ingredient): AdminIngredientResource
    {
        $validated = $request->validated();

        if (isset($validated['name'])) {
            $normalizedName = NameNormalizer::normalize($validated['name']);
            $nameExists = Ingredient::query()
                ->where('normalized_name', $normalizedName)
                ->where($ingredient->getKeyName(), '!=', $ingredient->getKey())
                ->exists();

            if ($nameExists) {
                throw ValidationException::withMessages([
                    'name' => 'An ingredient with this name already exists.',
                ]);
            }
        }

        $ingredient->update($validated);

        return new AdminIngredientResource($ingredient->refresh()->load(['shoppingCategory', 'aliases']));
    }

    public function mergePreview(
        PreviewIngredientMergeRequest $request,
        Ingredient $source,
        IngredientMergeAnalyzer $analyzer
    ): JsonResponse {
        $target = Ingredient::query()->findOrFail($request->validated('target_ingredient_id'));

        return response()->json($analyzer->analyze($source, $target)->toPreviewArray());
    }

    public function merge(
        PreviewIngredientMergeRequest $request,
        Ingredient $source,
        IngredientMerger $merger
    ): JsonResponse {
        try {
            $merge = $merger->merge(
                $source->id,
                (int) $request->validated('target_ingredient_id')
            );
        } catch (IngredientMergeBlockedException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'errors' => [
                    'target_ingredient_id' => [$exception->getMessage()],
                ],
                'conflicts' => $exception->analysis->conflictsForResponse(),
                'alias_name_collision' => $exception->analysis->aliasNameCollisionForResponse(),
            ], 422);
        }

        return response()->json([
            'target' => (new AdminIngredientResource($merge['target']))->resolve($request),
            'merged_source' => $merge['merged_source'],
            'result' => $merge['result'],
        ]);
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
