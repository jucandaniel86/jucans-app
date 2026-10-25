<?php

namespace App\Http\Requests\Project;

use App\Models\ProjectProgressNote;
use Illuminate\Foundation\Http\FormRequest;

class ListProjectProgressNotesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('viewAny', [ProjectProgressNote::class, $this->route('project')]);
    }

    public function rules(): array
    {
        return [
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
            'task_id' => ['sometimes', 'required', 'integer', 'min:1'],
            'from' => ['sometimes', 'required', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'required', 'date_format:Y-m-d', ...($this->filled('from') ? ['after_or_equal:from'] : [])],
            'general_only' => ['sometimes', 'boolean'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
