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
            'note_type_id' => $this->note_type_id,
            'configuration' => $this->configuration_snapshot,
            'configuration_captured_at' => $this->configuration_captured_at?->toISOString(),
            'values' => $this->whenLoaded('fieldValues', fn () => $this->fieldValues->map(fn ($row) => [
                'field_id' => $row->field_id, 'value' => $row->typedValue(),
            ])),
            'current_configuration_state' => $this->note_type_id === null ? null : [
                'note_type_archived_at' => $this->whenLoaded('noteType', fn () => $this->noteType?->archived_at?->toISOString()),
                'fields' => $this->whenLoaded('fieldValues', fn () => $this->fieldValues->map(fn ($row) => [
                    'field_id' => $row->field_id, 'type' => $row->field->type, 'unit' => $row->field->unit,
                    'archived_at' => $row->field->archived_at?->toISOString(),
                    'options' => $row->field->options->map(fn ($option) => [
                        'id' => $option->id, 'archived_at' => $option->archived_at?->toISOString(),
                    ]),
                ])),
            ],
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
