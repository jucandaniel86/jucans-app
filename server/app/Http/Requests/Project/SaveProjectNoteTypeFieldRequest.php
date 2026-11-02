<?php

namespace App\Http\Requests\Project;

class SaveProjectNoteTypeFieldRequest extends ProjectConfigurationRequest
{
    public function rules(): array
    {
        $creating = $this->routeIs('project-note-type-fields.create');
        $rules = [...parent::rules(),
            'required' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:1000000'],
            'field_id' => [$creating || $this->isMethod('PATCH') ? 'prohibited' : 'required', 'integer', 'min:1'],
            'note_type_id' => ['prohibited'],
        ];
        if ($creating) {
            $fieldRules = (new SaveProjectCustomFieldRequest)->rules();
            $rules['field'] = ['required', 'array:'.implode(',', array_keys($fieldRules))];
            foreach ($fieldRules as $key => $rule) {
                $rules['field.'.$key] = $rule;
            }
        } else {
            $rules['field'] = ['prohibited'];
        }

        return $rules;
    }
}
