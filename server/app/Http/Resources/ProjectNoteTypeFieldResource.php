<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProjectNoteTypeFieldResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'note_type_id' => $this->note_type_id, 'field_id' => $this->field_id,
            'sort_order' => $this->sort_order, 'required' => $this->required,
            'activated_at' => $this->activated_at, 'deactivated_at' => $this->deactivated_at,
            'field' => new ProjectCustomFieldResource($this->whenLoaded('field')),
        ];
    }
}
