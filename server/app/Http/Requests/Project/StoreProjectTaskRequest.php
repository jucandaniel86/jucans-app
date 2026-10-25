<?php

namespace App\Http\Requests\Project;

use App\Enums\ProjectStatus;
use App\Enums\ProjectTaskExecutionType;
use App\Enums\ProjectTaskPriority;
use App\Models\ProjectTask;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Validator;

class StoreProjectTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', [ProjectTask::class, $this->route('project')]);
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'execution_type' => ['sometimes', 'required', new Enum(ProjectTaskExecutionType::class)],
            'priority' => ['sometimes', 'required', new Enum(ProjectTaskPriority::class)],
            'status' => ['sometimes', 'required', new Enum(ProjectStatus::class)],
            'scheduled_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'scheduled_time' => ['sometimes', 'nullable', 'date_format:H:i'],
            'due_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'due_time' => ['sometimes', 'nullable', 'date_format:H:i'],
            'show_in_daily_widget' => ['sometimes', 'required', 'boolean'],
            'project_id' => ['prohibited'],
            'created_by' => ['prohibited'],
            'deleted_at' => ['prohibited'],
            'created_at' => ['prohibited'],
            'updated_at' => ['prohibited'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $task = $this->route('task');
            foreach (['scheduled', 'due'] as $prefix) {
                $date = $this->input($prefix.'_date', $task?->{$prefix.'_date'});
                $time = $this->input($prefix.'_time', $task?->{$prefix.'_time'});
                if ($time !== null && $date === null) {
                    $validator->errors()->add($prefix.'_time', 'A time requires its corresponding date.');
                }
            }
        });
    }
}
