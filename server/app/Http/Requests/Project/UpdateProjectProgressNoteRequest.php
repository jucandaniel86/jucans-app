<?php

namespace App\Http\Requests\Project;

class UpdateProjectProgressNoteRequest extends StoreProjectProgressNoteRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('note'));
    }

    public function rules(): array
    {
        $rules = parent::rules();
        foreach (['title', 'content', 'noted_at'] as $field) {
            $rules[$field] = ['sometimes', ...$rules[$field]];
        }

        return $rules;
    }
}
