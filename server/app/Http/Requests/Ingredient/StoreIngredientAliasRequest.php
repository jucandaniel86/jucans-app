<?php

namespace App\Http\Requests\Ingredient;

use Illuminate\Foundation\Http\FormRequest;

class StoreIngredientAliasRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'alias' => ['required', 'string', 'max:255'],
            'ingredient_id' => ['required', 'integer', 'exists:ingredients,id'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'alias' => trim((string) $this->input('alias', '')),
        ]);
    }
}
