<?php

namespace App\Http\Requests\Project;

use App\Enums\ProjectTaskExecutionType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProjectProgressRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('view', $this->route('project'))
            && (! $this->route('task') || $this->user()->can('view', $this->route('task')));
    }

    public function rules(): array
    {
        return [
            'date' => ['sometimes', 'date_format:Y-m-d'],
            'range' => ['sometimes', Rule::in(['7', '30', '90', 'all'])],
            'page' => ['sometimes', 'integer', 'min:1', 'max:100000'],
            'task_id' => ['sometimes', 'integer', 'min:1'],
            'execution_type' => ['sometimes', Rule::enum(ProjectTaskExecutionType::class)],
        ];
    }
}
