<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateIngredientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'default_unit' => [
                'sometimes',
                'nullable',
                'string',
                Rule::in(array_keys(config('food.units'))),
            ],
            'shopping_category_id' => [
                'sometimes',
                'nullable',
                'integer',
                'exists:shopping_categories,id',
            ],
            'is_shoppable' => ['sometimes', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('name')) {
            $this->merge(['name' => trim((string) $this->input('name'))]);
        }
    }
}
