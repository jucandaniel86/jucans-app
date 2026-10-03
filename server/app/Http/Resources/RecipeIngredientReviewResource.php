<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RecipeIngredientReviewResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'recipe' => [
                'id' => $this->recipe->id,
                'name' => $this->recipe->name,
            ],
            'ingredient' => [
                'id' => $this->ingredient->id,
                'name' => $this->ingredient->name,
                'default_unit' => $this->ingredient->default_unit,
            ],
            'value' => $this->value,
            'unit' => $this->unit,
            'raw_text' => $this->raw_text,
            'needs_review' => $this->needs_review,
        ];
    }
}
