<?php

namespace App\Http\Requests\Project;

use App\Enums\ProjectSharingMode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class StoreProjectCollaboratorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manageSharing', $this->route('project'));
    }

    public function rules(): array
    {
        $project = $this->route('project');

        return [
            'user_id' => [
                'required', 'integer', 'exists:users,id', Rule::notIn([$project->created_by]),
                Rule::unique('project_collaborators', 'user_id')->where('project_id', $project->id),
            ],
            'sharing_mode' => ['required', new Enum(ProjectSharingMode::class)],
            'task_ids' => [
                'sometimes', 'array', 'max:500',
                Rule::prohibitedIf($this->input('sharing_mode') === 'all_tasks'),
            ],
            'task_ids.*' => [
                'integer', 'distinct',
                Rule::exists('project_tasks', 'id')->where('project_id', $project->id)->whereNull('deleted_at'),
            ],
        ];
    }
}
