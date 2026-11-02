<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProjectCustomFieldOptionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'field_id' => $this->field_id, 'label' => $this->label,
            'sort_order' => $this->sort_order, 'archived_at' => $this->archived_at,
            'created_at' => $this->created_at, 'updated_at' => $this->updated_at,
        ];
    }
}
