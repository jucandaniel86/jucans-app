<?php

namespace App\Http\Requests\Project;

use App\Models\ProjectTaskEvaluation;
use Illuminate\Foundation\Http\FormRequest;

class StoreProjectTaskEvaluationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', [ProjectTaskEvaluation::class, $this->route('task')]);
    }

    public function rules(): array
    {
        return [
            'evaluation_date' => ['required', 'date_format:Y-m-d'],
            'score' => ['required', 'integer', 'min:1', 'max:10'],
            'note_id' => ['sometimes', 'nullable', 'integer'],
            'lock_version' => ['prohibited'],
            'created_by' => ['prohibited'],
            'task_id' => ['prohibited'],
            'project_id' => ['prohibited'],
            'active_slot' => ['prohibited'],
        ];
    }
}
