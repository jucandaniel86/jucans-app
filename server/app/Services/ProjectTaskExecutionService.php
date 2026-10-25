<?php

namespace App\Services;

use App\Enums\ProjectStatus;
use App\Enums\ProjectTaskCompletionAction;
use App\Enums\ProjectTaskExecutionType;
use App\Enums\ProjectTaskOccurrenceStatus;
use App\Models\Project;
use App\Models\ProjectTask;
use App\Models\ProjectTaskExecutionRequest;
use App\Models\ProjectTaskOccurrence;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ProjectTaskExecutionService
{
    public function __construct(private readonly ProjectTaskRecurrenceService $recurrence) {}

    public function validateKey(ProjectTask $task, string $key): void
    {
        Validator::make(['occurrence_key' => $key], ['occurrence_key' => $task->execution_type === ProjectTaskExecutionType::ONE_TIME
                ? ['required', 'in:single'] : ['required', 'date_format:Y-m-d'],
        ])->validate();
    }

    public function execute(User $user, Project $project, ProjectTask $task, string $key, ProjectTaskCompletionAction $action, array $input): ProjectTaskOccurrence
    {
        return DB::transaction(function () use ($user, $project, $task, $key, $action, $input): ProjectTaskOccurrence {
            $project = Project::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();
            $task = $project->tasks()->whereKey($task->id)->lockForUpdate()->firstOrFail()->setRelation('project', $project);
            Gate::forUser($user)->authorize('execute', $task);
            $this->validateKey($task, $key);
            $hash = hash('sha256', json_encode([
                $user->id, $key, $action->value, (int) $input['lock_version'], $input['performed_on'] ?? null,
            ], JSON_THROW_ON_ERROR));
            $receipt = ProjectTaskExecutionRequest::query()->where('task_id', $task->id)->where('request_id', $input['request_id'])->first();
            if ($receipt !== null) {
                abort_unless(hash_equals($receipt->payload_hash, $hash), 409, 'The request ID has already been used for different input.');

                return $task->occurrences()->whereKey($receipt->occurrence_id)->firstOrFail();
            }

            $occurrence = $task->occurrences()->where('occurrence_key', $key)->lockForUpdate()->first();
            $expected = null;
            if ($task->execution_type === ProjectTaskExecutionType::RECURRING
                && ($occurrence === null || ($occurrence->schedule_version_id === null && ! $occurrence->events()->exists()))) {
                $expected = $this->recurrence->expected($task, $key);
                if ($expected === null) {
                    throw ValidationException::withMessages(['occurrence_key' => 'This date is not eligible under the effective recurrence schedule and lifecycle history.']);
                }
            }
            $single = $task->execution_type === ProjectTaskExecutionType::ONE_TIME;
            // Undo is the necessary exception to the automatically completed one-time task.
            $completedSingle = $single && $occurrence?->status === ProjectTaskOccurrenceStatus::DONE;
            abort_unless($task->status === ProjectStatus::ACTIVE || ($completedSingle
                && in_array($action, [ProjectTaskCompletionAction::DONE, ProjectTaskCompletionAction::UNDO], true)), 403, 'This task is not active.');
            $current = $occurrence?->status ?? ProjectTaskOccurrenceStatus::PENDING;
            $version = $occurrence?->lock_version ?? 0;
            $target = $action->targetStatus();
            abort_if($current !== $target && $version !== (int) $input['lock_version'], 409, 'The occurrence was changed. Reload its current version.');

            if ($occurrence === null) {
                $occurrence = $task->occurrences()->create($expected?->getAttributes() ?? [
                    'occurrence_key' => $key,
                    'scheduled_date' => $single ? $task->scheduled_date : $key,
                    'scheduled_time' => $task->scheduled_time,
                    'due_date' => $task->due_date,
                    'due_time' => $task->due_time,
                ]);
            }
            $now = now('UTC');
            if ($current !== $target) {
                $previous = $action === ProjectTaskCompletionAction::UNDO ? $occurrence->events()->orderByDesc('id')->firstOrFail() : null;
                $performedOn = $input['performed_on'] ?? $previous?->performed_on
                    ?? ($single ? now(config('app.timezone'))->toDateString() : $key);
                if ($previous !== null && $performedOn !== $previous->performed_on) {
                    throw ValidationException::withMessages(['performed_on' => 'Undo must refer to the business date of the reversed event.']);
                }
                $occurrence->events()->create([
                    'action' => $action,
                    'actor_id' => $user->id,
                    'performed_on' => $performedOn,
                    'recorded_at' => $now,
                    'reverses_event_id' => $previous?->id,
                    'request_id' => $input['request_id'],
                ]);
                $occurrence->fill([
                    'status' => $target,
                    'completed_by' => $target === ProjectTaskOccurrenceStatus::DONE ? $user->id : null,
                    'completed_at' => $target === ProjectTaskOccurrenceStatus::DONE ? $now : null,
                    'lock_version' => $version + 1,
                ])->save();
                if ($single) {
                    $task->status = $target === ProjectTaskOccurrenceStatus::DONE ? ProjectStatus::COMPLETED : ProjectStatus::ACTIVE;
                    $task->save();
                }
                $project->forceFill(['last_activity_at' => $now])->save();
            }
            ProjectTaskExecutionRequest::create([
                'task_id' => $task->id,
                'occurrence_id' => $occurrence->id,
                'request_id' => $input['request_id'],
                'payload_hash' => $hash,
                'recorded_at' => $now,
            ]);

            return $occurrence->refresh();
        });
    }

    public function completePersistent(User $user, Project $project, ProjectTask $task): ProjectTask
    {
        return DB::transaction(function () use ($user, $project, $task): ProjectTask {
            $project = Project::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();
            $task = $project->tasks()->whereKey($task->id)->lockForUpdate()->firstOrFail()->setRelation('project', $project);
            Gate::forUser($user)->authorize('update', $task);
            abort_unless($task->execution_type === ProjectTaskExecutionType::PERSISTENT, 422, 'Only persistent tasks use this action.');
            abort_if($task->status === ProjectStatus::ARCHIVED, 403, 'Reactivate the task first.');
            if ($task->status !== ProjectStatus::COMPLETED) {
                $task->status = ProjectStatus::COMPLETED;
                $task->save();
                $project->forceFill(['last_activity_at' => now()])->save();
            }

            return $task;
        });
    }
}
