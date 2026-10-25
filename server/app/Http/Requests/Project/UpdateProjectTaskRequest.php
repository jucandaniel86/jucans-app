<?php

namespace App\Http\Requests\Project;

class UpdateProjectTaskRequest extends StoreProjectTaskRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('task'));
    }

    public function rules(): array
    {
        $rules = parent::rules();
        $rules['title'] = ['sometimes', ...$rules['title']];

        return $rules;
    }
}
