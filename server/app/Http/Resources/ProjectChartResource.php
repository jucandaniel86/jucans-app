<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProjectChartResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'project_id' => $this->project_id,
            'title' => $this->title, 'type' => $this->type, 'priority' => $this->priority, 'active' => $this->active,
            'total' => $this->total, 'y_min' => $this->y_min, 'y_max' => $this->y_max,
            'pie_aggregation' => $this->pie_aggregation,
            'pie_value_field_id' => $this->pie_value_field_id,
            'pie_value_field' => $this->whenLoaded('pieValueField', fn () => $this->pieValueField?->only(['id', 'name', 'type', 'unit'])),
            'field_ids' => $this->fields->modelKeys(),
            'fields' => $this->fields->map(fn ($field) => [
                'id' => $field->id, 'name' => $field->name, 'type' => $field->type,
                'aggregation' => $field->aggregation, 'unit' => $field->unit, 'archived' => $field->archived_at !== null,
            ])->all(),
        ];
    }
}
