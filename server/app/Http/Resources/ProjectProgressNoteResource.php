<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProjectProgressNoteResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'created_by' => $this->created_by,
            'updated_by' => $this->updated_by,
            'title' => $this->title,
            'content' => $this->content,
            'noted_at' => $this->noted_at,
            'task_ids' => $this->whenLoaded('tasks', fn () => $this->tasks->pluck('id')->all()),
            'tasks' => $this->whenLoaded('tasks', fn () => $this->tasks->map(fn ($task) => [
                'id' => $task->id, 'title' => $task->title, 'deleted_at' => $task->deleted_at?->toISOString(),
            ])),
            'evaluations' => ProjectTaskEvaluationResource::collection($this->whenLoaded('evaluations')),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            'deleted_at' => $this->deleted_at?->toISOString(),
        ];
    }
}
