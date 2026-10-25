<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProjectResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'category_id' => $this->category_id,
            'category' => new ProjectCategoryResource($this->whenLoaded('category')),
            'icon' => $this->icon,
            'progress_type' => $this->progress_type->value,
            'default_execution_type' => $this->default_execution_type->value,
            'note_template' => $this->note_template,
            'status' => $this->status->value,
            'created_by' => $this->created_by,
            'owner' => $this->whenLoaded('owner', fn () => [
                'id' => $this->owner->id,
                'username' => $this->owner->username,
                'avatar' => $this->owner->avatar,
            ]),
            'last_activity_at' => $this->last_activity_at->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            'status_periods' => ProjectStatusPeriodResource::collection($this->whenLoaded('statusPeriods')),
        ];
    }
}
