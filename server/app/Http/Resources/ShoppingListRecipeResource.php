<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ShoppingListRecipeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            ...(new ShoppingListResource($this->resource['list']))->toArray($request),
            'recipe' => new RecipeResource($this->resource['recipe']),
            'already_present' => $this->resource['already_present'],
            'items' => ShoppingListItemResource::collection($this->resource['items']),
        ];
    }
}
