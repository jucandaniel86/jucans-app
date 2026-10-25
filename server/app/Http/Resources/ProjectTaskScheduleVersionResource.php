<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProjectTaskScheduleVersionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'task_id' => $this->task_id,
            'version' => $this->version,
            'effective_from' => $this->effective_from,
            'effective_until' => $this->effective_until,
            'recurrence_type' => $this->recurrence_type->value,
            'interval_days' => $this->interval_days,
            'weekdays' => $this->weekdays,
            'month_day' => $this->month_day,
            'anchor_date' => $this->anchor_date,
            'start_date' => $this->start_date,
            'end_date' => $this->end_date,
            'scheduled_time' => $this->scheduled_time === null ? null : substr($this->scheduled_time, 0, 5),
            'created_by' => $this->created_by,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
