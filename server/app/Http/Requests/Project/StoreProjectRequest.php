<?php

namespace App\Http\Requests\Project;

use App\Enums\ProjectProgressType;
use App\Enums\ProjectStatus;
use App\Enums\ProjectTaskExecutionType;
use App\Models\Project;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class StoreProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Project::class);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'category_id' => ['required', 'integer', 'exists:project_categories,id'],
            'icon' => ['sometimes', 'required', 'string', 'max:255'],
            'progress_type' => ['sometimes', 'required', new Enum(ProjectProgressType::class)],
            'default_execution_type' => ['sometimes', 'required', new Enum(ProjectTaskExecutionType::class)],
            'note_template' => ['sometimes', 'nullable', 'string'],
            'status' => ['sometimes', 'required', new Enum(ProjectStatus::class)],
            'created_by' => ['prohibited'],
            'owner_id' => ['prohibited'],
            'last_activity_at' => ['prohibited'],
            'created_at' => ['prohibited'],
            'updated_at' => ['prohibited'],
        ];
    }
}
