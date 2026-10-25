<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProjectTaskEvaluationRevisionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'evaluation_id' => $this->evaluation_id,
            'actor_id' => $this->actor_id,
            'previous_score' => $this->previous_score,
            'new_score' => $this->new_score,
            'previous_note_id' => $this->previous_note_id,
            'new_note_id' => $this->new_note_id,
            'recorded_at' => $this->recorded_at->toISOString(),
        ];
    }
}
