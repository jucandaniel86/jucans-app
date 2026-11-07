<?php

namespace App\Http\Requests\Project;

use Illuminate\Validation\Rule;

class SaveProjectChartRequest extends ProjectConfigurationRequest
{
    public function rules(): array
    {
        $decimal = ['nullable', 'numeric', 'regex:/^-?\d{1,14}(\.\d{1,6})?$/'];
        $presence = $this->isMethod('PATCH') ? 'sometimes' : 'required';

        return [...parent::rules(),
            'title' => [$presence, 'required', 'string', 'max:255'],
            'type' => [$presence, 'required', Rule::in(['pie', 'line', 'bar'])],
            'priority' => [$presence, 'required', 'integer', 'between:-2147483648,2147483647'],
            'active' => [$presence, 'required', 'boolean'],
            'pie_aggregation' => ['sometimes', 'nullable', Rule::in(['count', 'sum'])],
            'pie_value_field_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'total' => $decimal, 'y_min' => $decimal, 'y_max' => $decimal,
            'field_ids' => [$presence, 'required', 'array', 'min:1', 'max:12'],
            'field_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
        ];
    }
}
