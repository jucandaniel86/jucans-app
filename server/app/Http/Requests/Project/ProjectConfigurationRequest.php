<?php

namespace App\Http\Requests\Project;

use Illuminate\Foundation\Http\FormRequest;

class ProjectConfigurationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('project'));
    }

    public function rules(): array
    {
        return [
            'project_id' => ['prohibited'], 'created_at' => ['prohibited'], 'updated_at' => ['prohibited'],
            'archived_at' => ['prohibited'], 'first_value_at' => ['prohibited'], 'is_default' => ['prohibited'],
        ];
    }
}
