<?php

namespace App\Http\Requests\Project;

use Illuminate\Validation\Rule;

class SaveProjectCustomFieldRequest extends ProjectConfigurationRequest
{
    public function rules(): array
    {
        $presence = $this->isMethod('PATCH') ? 'sometimes' : 'required';

        return [...parent::rules(),
            'name' => [$presence, 'required', 'string', 'max:255'],
            'key' => [$presence, 'required', 'string', 'max:100', 'regex:/^[a-z][a-z0-9_]*$/'],
            'type' => [$presence, 'required', Rule::in(['number', 'select', 'boolean', 'text'])],
            'description' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'unit' => ['sometimes', 'nullable', 'string', 'max:100'],
            'min' => ['sometimes', 'nullable', 'numeric'], 'max' => ['sometimes', 'nullable', 'numeric'],
            'step' => ['sometimes', 'nullable', 'numeric'],
            'aggregation' => ['sometimes', 'nullable', Rule::in(['average', 'sum', 'min', 'max', 'latest'])],
        ];
    }
}
