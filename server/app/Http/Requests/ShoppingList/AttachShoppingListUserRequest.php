<?php

namespace App\Http\Requests\ShoppingList;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AttachShoppingListUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manageSharing', $this->route('shoppingList'));
    }

    public function rules(): array
    {
        return ['user_id' => [
            'required', 'integer', Rule::exists('users', 'id'),
            Rule::notIn([$this->route('shoppingList')->created_by]),
        ]];
    }
}
