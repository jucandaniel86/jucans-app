<?php

namespace App\Http\Requests\ShoppingList;

use App\Models\ShoppingList;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateShoppingListVisibilityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('changeVisibility', $this->route('shoppingList'));
    }

    public function rules(): array
    {
        return ['visibility' => ['required', 'string', Rule::in(ShoppingList::VISIBILITIES)]];
    }
}
