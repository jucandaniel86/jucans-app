<?php

namespace App\Http\Requests\Recipe;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreRecipeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'image' => [
                'nullable',
                'image',
                'mimes:jpg,jpeg,png,webp',
                'max:8192',
                'dimensions:max_width=6000,max_height=6000',
            ],
            'url' => ['nullable', 'url', 'max:2048'],
            'tags' => ['sometimes', 'array'],
            'tags.*' => ['integer', 'distinct', 'exists:food_tags,id'],
            'ingredients' => ['sometimes', 'array', 'max:100'],
            'ingredients.*' => ['array'],
            'ingredients.*.ingredient_id' => ['nullable', 'integer', 'exists:ingredients,id'],
            'ingredients.*.name' => ['nullable', 'string', 'max:255'],
            'ingredients.*.default_unit' => ['nullable', 'string', Rule::in(array_keys(config('food.units')))],
            'ingredients.*.value' => ['nullable', 'numeric', 'min:0', 'max:999999999.999'],
            'ingredients.*.unit' => ['nullable', 'string', Rule::in(array_keys(config('food.units')))],
            'ingredients.*.raw_text' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $ingredients = $this->input('ingredients', []);

            if (! is_array($ingredients)) {
                return;
            }

            foreach ($ingredients as $index => $ingredient) {
                if (! is_array($ingredient)) {
                    continue;
                }

                $hasId = isset($ingredient['ingredient_id']);
                $hasName = trim((string) ($ingredient['name'] ?? '')) !== '';

                if ($hasId === $hasName) {
                    $validator->errors()->add(
                        "ingredients.{$index}",
                        'Choose either an existing ingredient or a new ingredient name.'
                    );
                }
            }
        });
    }
}
