<?php

namespace App\Http\Requests\Project;

class UpdateProjectCollaboratorRequest extends StoreProjectCollaboratorRequest
{
    public function rules(): array
    {
        $rules = parent::rules();
        $rules['user_id'] = ['prohibited'];

        return $rules;
    }
}
