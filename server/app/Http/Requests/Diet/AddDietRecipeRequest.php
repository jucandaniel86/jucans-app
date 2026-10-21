<?php

namespace App\Http\Requests\Diet;

use App\Enums\DietRecipePriority;
use Illuminate\Validation\Rules\Enum;

class AddDietRecipeRequest extends UpdateDietRecipeRequest
{
    public function rules(): array
    {
        return [
            'recipe_id' => ['required', 'integer', 'exists:recipes,id'],
            'priority' => ['sometimes', new Enum(DietRecipePriority::class)],
        ];
    }
}
