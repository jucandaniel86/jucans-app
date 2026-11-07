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
            'review_task_id' => ['required_with:review_occurrence_key', 'integer', 'min:1'],
            'review_occurrence_key' => ['required_with:review_task_id', 'string', 'max:10'],
            'review' => ['prohibited'],
            'user_id' => ['prohibited'],
            'note_type_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'values' => ['sometimes', 'array', 'max:1000'],
            'values.*' => ['required', 'array:field_id,value'],
            'values.*.field_id' => ['required', 'integer', 'min:1'],
            'values.*.value' => ['present'],
            'configuration' => ['prohibited'],
            'configuration_snapshot' => ['prohibited'],
            'configuration_captured_at' => ['prohibited'],
            'first_value_at' => ['prohibited'],
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
