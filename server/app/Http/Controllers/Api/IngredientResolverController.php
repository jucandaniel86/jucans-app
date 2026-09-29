<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Ingredient\ResolveIngredientsRequest;
use App\Services\IngredientResolver;
use Illuminate\Http\JsonResponse;

class IngredientResolverController extends Controller
{
    public function __invoke(
        ResolveIngredientsRequest $request,
        IngredientResolver $resolver
    ): JsonResponse {
        return response()->json([
            'data' => $resolver->resolve($request->validated('ingredients')),
        ]);
    }
}
