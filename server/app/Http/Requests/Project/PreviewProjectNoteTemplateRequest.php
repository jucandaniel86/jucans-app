<?php

namespace App\Http\Requests\Project;

use App\Models\ProjectTask;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PreviewProjectNoteTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('view', $this->route('project'));
    }

    public function rules(): array
    {
        return [
            'date' => ['sometimes', 'required', 'date_format:Y-m-d'],
            'task_ids' => ['sometimes', 'array', 'max:100'],
            'task_ids.*' => ['integer', 'distinct', Rule::exists('project_tasks', 'id')
                ->where('project_id', $this->route('project')->id)->whereNull('deleted_at')
                ->where(fn ($query) => $query->whereIn('id', ProjectTask::query()->visibleTo($this->user())->select('project_tasks.id')))],
        ];
    }
}
