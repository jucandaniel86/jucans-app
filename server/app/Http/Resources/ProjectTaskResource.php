<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProjectTaskResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'created_by' => $this->created_by,
            'title' => $this->title,
            'description' => $this->description,
            'execution_type' => $this->execution_type->value,
            'priority' => $this->priority->value,
            'status' => $this->status->value,
            'scheduled_date' => $this->scheduled_date,
            'scheduled_time' => $this->scheduled_time === null ? null : substr($this->scheduled_time, 0, 5),
            'due_date' => $this->due_date,
            'due_time' => $this->due_time === null ? null : substr($this->due_time, 0, 5),
            'show_in_daily_widget' => $this->show_in_daily_widget,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            'deleted_at' => $this->deleted_at?->toISOString(),
        ];
    }
}
