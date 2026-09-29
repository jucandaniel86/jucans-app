<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RecipeIngredientResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->ingredient->id,
            'name' => $this->ingredient->name,
            'value' => $this->value,
            'unit' => $this->unit,
            'raw_text' => $this->raw_text,
        ];
    }
}
