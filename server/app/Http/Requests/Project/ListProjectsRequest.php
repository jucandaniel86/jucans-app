<?php

namespace App\Http\Requests\Project;

use App\Models\Project;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListProjectsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('viewAny', Project::class);
    }

    public function rules(): array
    {
        return [
            'status' => ['sometimes', 'required', Rule::in(['active', 'completed', 'archived', 'inactive', 'all'])],
            'category_id' => ['sometimes', 'required', 'integer', 'exists:project_categories,id'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
