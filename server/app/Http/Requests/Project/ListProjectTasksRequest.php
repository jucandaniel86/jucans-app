<?php

namespace App\Http\Requests\Project;

use App\Enums\ProjectStatus;
use App\Enums\ProjectTaskExecutionType;
use App\Enums\ProjectTaskPriority;
use App\Models\ProjectTask;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class ListProjectTasksRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('viewAny', [ProjectTask::class, $this->route('project')]);
    }

    public function rules(): array
    {
        return [
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
            'execution_type' => ['sometimes', 'required', new Enum(ProjectTaskExecutionType::class)],
            'priority' => ['sometimes', 'required', new Enum(ProjectTaskPriority::class)],
            'status' => ['sometimes', 'required', new Enum(ProjectStatus::class)],
            'sort' => ['sometimes', 'required', Rule::in(['priority', 'date', 'overdue'])],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
