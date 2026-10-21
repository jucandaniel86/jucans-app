<?php

namespace App\Http\Requests\Admin;

class UpdateAuthorRequest extends StoreAuthorRequest
{
    public function rules(): array
    {
        $rules = parent::rules();
        $rules['name'] = ['sometimes', 'required', 'string', 'max:255'];
        $rules['remove_icon'] = ['sometimes', 'boolean'];

        return $rules;
    }
}
