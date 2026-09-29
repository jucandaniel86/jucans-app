<?php

namespace App\Http\Requests\Recipe;

class UpdateRecipeRequest extends StoreRecipeRequest
{
    public function rules(): array
    {
        $rules = parent::rules();
        $rules['name'] = ['sometimes', 'required', 'string', 'max:255'];
        $rules['description'] = ['sometimes', 'nullable', 'string'];
        $rules['image'] = ['sometimes', ...$rules['image']];
        $rules['remove_image'] = ['sometimes', 'boolean'];
        $rules['url'] = ['sometimes', 'nullable', 'url', 'max:2048'];
        $rules['tags_present'] = ['sometimes', 'boolean'];
        $rules['ingredients_present'] = ['sometimes', 'boolean'];

        return $rules;
    }
}
