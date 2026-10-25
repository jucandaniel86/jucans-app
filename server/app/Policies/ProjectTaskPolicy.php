<?php

namespace App\Policies;

use App\Enums\ProjectStatus;
use App\Enums\ProjectTaskExecutionType;
use App\Models\Project;
use App\Models\ProjectTask;
use App\Models\User;

class ProjectTaskPolicy
{
    public function viewAny(User $user, Project $project): bool
    {
        return $user->can('view', $project);
    }

    public function create(User $user, Project $project): bool
    {
        return $project->status === ProjectStatus::ACTIVE && $user->can('view', $project);
    }

    public function view(User $user, ProjectTask $task): bool
    {
        return ProjectTask::withTrashed()->visibleTo($user)->whereKey($task->id)->exists();
    }

    public function update(User $user, ProjectTask $task): bool
    {
        return ! $task->trashed() && $this->canManage($user, $task);
    }

    public function delete(User $user, ProjectTask $task): bool
    {
        return ! $task->trashed() && $this->canManage($user, $task);
    }

    public function restore(User $user, ProjectTask $task): bool
    {
        return $task->trashed() && $this->canManage($user, $task);
    }

    public function execute(User $user, ProjectTask $task): bool
    {
        // Completed-task exceptions are checked against locked occurrence state.
        return ! $task->trashed() && $task->project->status === ProjectStatus::ACTIVE
            && $task->status !== ProjectStatus::ARCHIVED && $this->view($user, $task);
    }

    public function configureSchedule(User $user, ProjectTask $task): bool
    {
        return $task->execution_type === ProjectTaskExecutionType::RECURRING
            && $task->status === ProjectStatus::ACTIVE && $this->update($user, $task);
    }

    private function canManage(User $user, ProjectTask $task): bool
    {
        return $task->project->status === ProjectStatus::ACTIVE
            && ($task->project->isOwnedBy($user) || (int) $task->created_by === (int) $user->id)
            && $this->view($user, $task);
    }
}
