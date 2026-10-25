<?php

namespace App\Http\Requests\Project;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SetProjectTaskGrantsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manageSharing', $this->route('project'));
    }

    public function rules(): array
    {
        return [
            'task_ids' => ['present', 'array', 'max:500'],
            'task_ids.*' => [
                'integer', 'distinct',
                Rule::exists('project_tasks', 'id')->where('project_id', $this->route('project')->id)->whereNull('deleted_at'),
            ],
        ];
    }
}
