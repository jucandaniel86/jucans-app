<?php

namespace App\Http\Requests\Diet;

use App\Enums\DietRecipePriority;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class UpdateDietRecipeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'priority' => ['required', new Enum(DietRecipePriority::class)],
        ];
    }
}
