<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProjectNoteTypeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'project_id' => $this->project_id, 'name' => $this->name,
            'description' => $this->description, 'template' => $this->template,
            'is_default' => (bool) $this->is_default, 'archived_at' => $this->archived_at,
            'is_shopping' => (bool) $this->is_shopping,
            'shopping_amount_field_id' => $this->shopping_amount_field_id,
            'shopping_provider_field_id' => $this->shopping_provider_field_id,
            'shopping_provider_option_id' => $this->shopping_provider_option_id,
            'created_at' => $this->created_at, 'updated_at' => $this->updated_at,
        ];
    }
}
