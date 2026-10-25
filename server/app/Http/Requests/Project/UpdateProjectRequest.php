<?php

namespace App\Http\Requests\Project;

class UpdateProjectRequest extends StoreProjectRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('project'));
    }

    public function rules(): array
    {
        $rules = parent::rules();
        $rules['name'] = ['sometimes', ...$rules['name']];
        $rules['category_id'] = ['sometimes', ...$rules['category_id']];
        $rules['status'] = ['prohibited'];

        return $rules;
    }
}
