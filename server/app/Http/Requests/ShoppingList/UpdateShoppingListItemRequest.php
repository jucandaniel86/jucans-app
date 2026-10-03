<?php

namespace App\Http\Requests\ShoppingList;

use Illuminate\Foundation\Http\FormRequest;

class UpdateShoppingListItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['is_checked' => ['required', 'boolean']];
    }
}
