<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Ingredient\SearchIngredientsRequest;
use App\Http\Resources\IngredientResource;
use App\Models\Ingredient;
use App\Support\NameNormalizer;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class IngredientController extends Controller
{
    public function search(SearchIngredientsRequest $request): AnonymousResourceCollection
    {
        $query = NameNormalizer::normalize($request->validated('query'));

        return IngredientResource::collection(
            Ingredient::query()
                ->where('normalized_name', 'like', '%'.$this->escapeLike($query).'%')
                ->orderBy('normalized_name')
                ->limit(20)
                ->get()
        );
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
