<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ShoppingListItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'ingredient_id' => $this->ingredient_id,
            'name' => $this->name,
            'calculated_quantity' => $this->calculated_quantity,
            'quantity' => $this->quantity,
            'unit' => $this->unit,
            'is_checked' => $this->is_checked,
            'quantity_overridden' => $this->quantity_overridden,
            'shopping_category' => new ShoppingCategoryResource($this->whenLoaded('shoppingCategory')),
            'sources' => $this->whenLoaded('sources', fn () => $this->sources->map(fn ($source) => [
                'id' => $source->id,
                'recipe_id' => $source->recipe_id,
                'quantity' => $source->quantity,
                'unit' => $source->unit,
            ])),
        ];
    }
}
