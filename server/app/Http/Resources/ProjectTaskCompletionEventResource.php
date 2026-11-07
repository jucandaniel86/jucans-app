<?php

namespace App\Http\Resources;

use App\Enums\ProjectTaskCompletionAction;
use App\Enums\ProjectTaskOccurrenceStatus;
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
            'previous_status' => $this->previous_action === null ? ProjectTaskOccurrenceStatus::PENDING->value
                : ProjectTaskCompletionAction::from($this->previous_action)->targetStatus()->value,
            'new_status' => $this->action->targetStatus()->value,
            'actor' => $this->whenLoaded('actor', fn () => ['id' => $this->actor->id, 'username' => $this->actor->username]),
            'actor_id' => $this->actor_id,
            'performed_on' => $this->performed_on,
            'recorded_at' => $this->recorded_at->toISOString(),
            'reverses_event_id' => $this->reverses_event_id,
            'request_id' => $this->request_id,
        ];
    }
}
