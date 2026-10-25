<?php

namespace App\Http\Requests\Project;

use App\Models\ProjectProgressNote;
use App\Models\ProjectTask;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProjectProgressNoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', [ProjectProgressNote::class, $this->route('project')]);
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'content' => ['required', 'string'],
            'noted_at' => ['required', 'date_format:Y-m-d'],
            'task_ids' => ['sometimes', 'array', 'max:100'],
            'task_ids.*' => ['integer', 'distinct', Rule::exists('project_tasks', 'id')
                ->where('project_id', $this->route('project')->id)->whereNull('deleted_at')
                ->where(fn ($query) => $query->whereIn('id', ProjectTask::query()->visibleTo($this->user())->select('project_tasks.id')))],
            'project_id' => ['prohibited'],
            'created_by' => ['prohibited'],
            'updated_by' => ['prohibited'],
            'deleted_at' => ['prohibited'],
        ];
    }
}
