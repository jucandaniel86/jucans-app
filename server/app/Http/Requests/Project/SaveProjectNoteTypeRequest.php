<?php

namespace App\Http\Requests\Project;

class SaveProjectNoteTypeRequest extends ProjectConfigurationRequest
{
    public function rules(): array
    {
        return [...parent::rules(),
            'name' => [$this->isMethod('PATCH') ? 'sometimes' : 'required', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'template' => ['sometimes', 'nullable', 'string', 'max:100000'],
            'is_shopping' => ['sometimes', 'boolean'],
            'shopping_amount_field_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'shopping_provider_field_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'shopping_provider_option_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ];
    }
}
