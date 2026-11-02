<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProjectCustomFieldResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'project_id' => $this->project_id, 'name' => $this->name,
            'key' => $this->key, 'description' => $this->description, 'type' => $this->type,
            'unit' => $this->unit, 'min' => $this->min, 'max' => $this->max, 'step' => $this->step,
            'aggregation' => $this->aggregation, 'archived_at' => $this->archived_at,
            'first_value_at' => $this->first_value_at,
            'created_at' => $this->created_at, 'updated_at' => $this->updated_at,
        ];
    }
}
