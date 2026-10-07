<?php

namespace App\Http\Requests\ShoppingList;

use Illuminate\Foundation\Http\FormRequest;

class UpdateShoppingListItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $values = [];

        if ($this->has('name') && is_string($this->input('name'))) {
            $values['name'] = trim($this->input('name'));
        }

        if ($this->has('unit')) {
            $unit = $this->input('unit');
            if (is_string($unit)) {
                $unit = trim($unit);
                $values['unit'] = $unit === '' ? null : $unit;
            }
        }

        $this->merge($values);
    }

    public function rules(): array
    {
        return [
            'is_checked' => ['sometimes', 'boolean'],
            'name' => ['sometimes', 'required', 'string'],
            'quantity' => ['sometimes', 'nullable', 'numeric'],
            'unit' => ['sometimes', 'nullable', 'string'],
            'reset_quantity' => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            if (! $this->hasAny(['is_checked', 'name', 'quantity', 'unit', 'reset_quantity'])) {
                $validator->errors()->add('item', 'At least one item field must be provided.');
            }
        });
    }
}
