<?php

namespace App\Http\Requests\Project;

class ReorderProjectConfigurationRequest extends ProjectConfigurationRequest
{
    public function rules(): array
    {
        return [...parent::rules(), 'ids' => ['present', 'array', 'max:1000'], 'ids.*' => ['required', 'integer', 'distinct', 'min:1']];
    }
}
