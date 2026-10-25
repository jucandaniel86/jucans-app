<?php

namespace App\Http\Requests\Project;

use Illuminate\Foundation\Http\FormRequest;

class ExecuteProjectTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('execute', $this->route('task'));
    }

    public function rules(): array
    {
        return [
            'request_id' => ['required', 'string', 'max:100'],
            'lock_version' => ['required', 'integer', 'min:0'],
            'performed_on' => ['sometimes', 'required', 'date_format:Y-m-d'],
        ];
    }
}
