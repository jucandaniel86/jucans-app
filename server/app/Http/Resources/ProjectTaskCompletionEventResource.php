<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProjectTaskCompletionEventResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'occurrence_id' => $this->occurrence_id,
            'action' => $this->action->value,
            'actor_id' => $this->actor_id,
            'performed_on' => $this->performed_on,
            'recorded_at' => $this->recorded_at->toISOString(),
            'reverses_event_id' => $this->reverses_event_id,
            'request_id' => $this->request_id,
        ];
    }
}
