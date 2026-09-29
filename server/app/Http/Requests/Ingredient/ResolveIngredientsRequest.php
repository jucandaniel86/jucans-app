<?php

namespace App\Http\Requests\Ingredient;

use Illuminate\Foundation\Http\FormRequest;

class ResolveIngredientsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'ingredients' => ['required', 'array', 'min:1', 'max:100'],
            'ingredients.*' => ['required', 'string', 'max:500'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (! is_array($this->input('ingredients'))) {
            return;
        }

        $ingredients = array_map(
            fn ($line) => is_string($line) ? trim($line) : $line,
            $this->input('ingredients')
        );

        $ingredients = array_values(array_filter(
            $ingredients,
            fn ($line) => $line !== null && (! is_string($line) || $line !== '')
        ));

        $this->replace(array_merge($this->all(), ['ingredients' => $ingredients]));
    }
}
