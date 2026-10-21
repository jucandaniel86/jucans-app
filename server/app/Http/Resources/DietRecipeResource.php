<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

class DietRecipeResource extends RecipeResource
{
    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),
            'priority' => $this->pivot->priority,
        ];
    }
}
