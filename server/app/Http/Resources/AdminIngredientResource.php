<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminIngredientResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'default_unit' => $this->default_unit,
            'is_shoppable' => $this->is_shoppable,
            'shopping_category' => ShoppingCategoryResource::make($this->whenLoaded('shoppingCategory')),
            'aliases' => $this->whenLoaded('aliases', fn () => $this->aliases->map(fn ($alias) => [
                'id' => $alias->id,
                'alias' => $alias->alias,
            ])->values()),
        ];
    }
}
