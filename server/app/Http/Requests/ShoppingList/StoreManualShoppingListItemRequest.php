<?php

namespace App\Http\Requests\ShoppingList;

use Illuminate\Foundation\Http\FormRequest;

class StoreManualShoppingListItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $name = $this->input('name', '');
        $values = [];

        if (is_string($name)) {
            $values['name'] = trim($name);
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
            'name' => ['required', 'string'],
            'quantity' => ['nullable', 'numeric', 'gt:0'],
            'unit' => ['nullable', 'string'],
        ];
    }
}
