<?php

namespace App\Services;

use App\Enums\ProjectStatus;
use App\Enums\ProjectTaskExecutionType;
use App\Enums\ProjectTaskOccurrenceStatus;
use App\Models\Project;
use App\Models\ProjectTask;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ProjectTaskService
{
    public function create(User $user, Project $project, array $attributes): ProjectTask
    {
        return DB::transaction(function () use ($user, $project, $attributes): ProjectTask {
            $project = Project::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($user)->authorize('create', [ProjectTask::class, $project]);
            $task = new ProjectTask($this->normalizeTimes($attributes));
            $task->forceFill([
                'project_id' => $project->id,
                'created_by' => $user->id,
                'execution_type' => $attributes['execution_type'] ?? $project->default_execution_type,
            ]);
            $this->validateCalendarFields($task);
            if ($task->execution_type === ProjectTaskExecutionType::ONE_TIME && $task->status === ProjectStatus::COMPLETED) {
                throw ValidationException::withMessages(['status' => 'Use the occurrence Done action to complete a one-time task.']);
            }
            $task->save();
            $this->recordActivity($project);

            return $task->refresh();
        });
    }

    public function update(User $user, Project $project, ProjectTask $task, array $attributes): ProjectTask
    {
        return DB::transaction(function () use ($user, $project, $task, $attributes): ProjectTask {
            $project = Project::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();
            $task = $this->lockedTask($project, $task);
            Gate::forUser($user)->authorize('update', $task);
            $task->fill($this->normalizeTimes($attributes));
            $this->validateCalendarFields($task);
            $hasOccurrences = $task->occurrences()->exists();
            abort_if($task->isDirty('execution_type') && ($hasOccurrences || $task->scheduleVersions()->exists()), 409, 'Execution type cannot change after occurrences or schedule history exist.');
            if ($task->execution_type === ProjectTaskExecutionType::ONE_TIME && ($task->isDirty('status') || $task->isDirty('execution_type'))) {
                $single = $task->occurrences()->where('occurrence_key', 'single')->first();
                if ($task->status === ProjectStatus::COMPLETED && $single?->status !== ProjectTaskOccurrenceStatus::DONE) {
                    throw ValidationException::withMessages(['status' => 'Use the occurrence Done action to complete a one-time task.']);
                }
                if ($task->status === ProjectStatus::ACTIVE && $single?->status === ProjectTaskOccurrenceStatus::DONE) {
                    // Unarchiving restores the completed projection so Undo can reactivate it.
                    abort_unless($task->getRawOriginal('status') === 'archived', 409, 'Use Undo to reactivate the completed one-time task.');
                    $task->status = ProjectStatus::COMPLETED;
                }
            }
            if ($task->isDirty()) {
                $task->save();
                $this->recordActivity($project);
            }

            return $task;
        });
    }

    public function delete(User $user, Project $project, ProjectTask $task): void
    {
        DB::transaction(function () use ($user, $project, $task): void {
            $project = Project::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();
            $task = $this->lockedTask($project, $task);
            Gate::forUser($user)->authorize('delete', $task);
            $task->delete();
            $task->deletionPeriods()->create(['deleted_at' => $task->deleted_at, 'deleted_by' => $user->id]);
            $this->recordActivity($project);
        });
    }

    public function restore(User $user, Project $project, ProjectTask $task): ProjectTask
    {
        return DB::transaction(function () use ($user, $project, $task): ProjectTask {
            $project = Project::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();
            $task = $this->lockedTask($project, $task, true);
            Gate::forUser($user)->authorize('restore', $task);
            $period = $task->deletionPeriods()->whereNull('restored_at')->lockForUpdate()->first();
            // Legacy currently-deleted tasks still carry their known deletion timestamp.
            $period ??= $task->deletionPeriods()->create(['deleted_at' => $task->deleted_at]);
            $task->restore();
            $period->update(['restored_at' => now('UTC'), 'restored_by' => $user->id]);
            $this->recordActivity($project);

            return $task;
        });
    }

    private function lockedTask(Project $project, ProjectTask $task, bool $deleted = false): ProjectTask
    {
        $query = $project->tasks();
        if ($deleted) {
            $query->onlyTrashed();
        }

        return $query->whereKey($task->id)->lockForUpdate()->firstOrFail()->setRelation('project', $project);
    }

    private function normalizeTimes(array $attributes): array
    {
        foreach (['scheduled_time', 'due_time'] as $field) {
            if (isset($attributes[$field]) && strlen($attributes[$field]) === 5) {
                $attributes[$field] .= ':00';
            }
        }

        return $attributes;
    }

    private function validateCalendarFields(ProjectTask $task): void
    {
        foreach (['scheduled', 'due'] as $prefix) {
            if ($task->{$prefix.'_time'} !== null && $task->{$prefix.'_date'} === null) {
                throw ValidationException::withMessages([$prefix.'_time' => 'A time requires its corresponding date.']);
            }
        }
    }

    private function recordActivity(Project $project): void
    {
        $project->forceFill(['last_activity_at' => now()])->save();
    }
}
