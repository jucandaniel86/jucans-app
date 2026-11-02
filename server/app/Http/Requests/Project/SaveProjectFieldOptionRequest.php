<?php

namespace App\Http\Requests\Project;

class SaveProjectFieldOptionRequest extends ProjectConfigurationRequest
{
    public function rules(): array
    {
        return [...parent::rules(),
            'label' => [$this->isMethod('PATCH') ? 'sometimes' : 'required', 'required', 'string', 'max:255'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:1000000'],
            'field_id' => ['prohibited'],
        ];
    }
}
