<?php

namespace App\Http\Requests\Project;

class UpdateProjectTaskEvaluationRequest extends StoreProjectTaskEvaluationRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('evaluation'));
    }

    public function rules(): array
    {
        $rules = parent::rules();
        $rules['evaluation_date'] = ['prohibited'];
        $rules['score'] = ['sometimes', ...$rules['score']];
        $rules['lock_version'] = ['required', 'integer', 'min:0'];

        return $rules;
    }
}
