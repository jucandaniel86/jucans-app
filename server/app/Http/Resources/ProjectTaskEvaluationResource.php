<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProjectTaskEvaluationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'task_id' => $this->task_id,
            'evaluation_date' => $this->evaluation_date,
            'score' => $this->score,
            'created_by' => $this->created_by,
            'note_id' => $this->note_id,
            'lock_version' => $this->lock_version,
            'is_active' => $this->active_slot === 1 && $this->withdrawn_at === null,
            'withdrawn_at' => $this->withdrawn_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
