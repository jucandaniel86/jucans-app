<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProjectTaskOccurrenceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'task_id' => $this->task_id,
            'occurrence_key' => $this->occurrence_key,
            'schedule_version_id' => $this->schedule_version_id,
            'is_recorded' => $this->resource->exists,
            'is_scheduled' => $this->when(array_key_exists('is_scheduled', $this->resource->getAttributes()), fn () => (bool) $this->is_scheduled),
            'scheduled_date' => $this->scheduled_date,
            'scheduled_time' => $this->scheduled_time === null ? null : substr($this->scheduled_time, 0, 5),
            'due_date' => $this->due_date,
            'due_time' => $this->due_time === null ? null : substr($this->due_time, 0, 5),
            'status' => $this->status->value,
            'completed_by' => $this->completed_by,
            'completed_at' => $this->completed_at?->toISOString(),
            'lock_version' => $this->lock_version,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
