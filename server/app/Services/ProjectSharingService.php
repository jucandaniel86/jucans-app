<?php

namespace App\Services;

use App\Enums\ProjectSharingMode;
use App\Models\Project;
use App\Models\ProjectCollaborator;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ProjectSharingService
{
    public function add(User $owner, Project $project, array $attributes): ProjectCollaborator
    {
        return DB::transaction(function () use ($owner, $project, $attributes): ProjectCollaborator {
            $project = $this->editableProject($owner, $project);
            if ((int) $attributes['user_id'] === (int) $project->created_by
                || $project->collaborators()->where('user_id', $attributes['user_id'])->exists()) {
                throw ValidationException::withMessages(['user_id' => 'The owner or an existing collaborator cannot be added.']);
            }
            User::query()->findOrFail($attributes['user_id']);
            $member = $project->collaborators()->create([
                'user_id' => $attributes['user_id'],
                'sharing_mode' => $attributes['sharing_mode'],
            ]);
            $this->replaceGrants($project, $member, $attributes['task_ids'] ?? []);
            $this->recordActivity($project);

            return $this->details($member);
        });
    }

    public function update(User $owner, Project $project, ProjectCollaborator $member, array $attributes): ProjectCollaborator
    {
        return DB::transaction(function () use ($owner, $project, $member, $attributes): ProjectCollaborator {
            $project = $this->editableProject($owner, $project);
            $member = $project->collaborators()->whereKey($member->id)->lockForUpdate()->firstOrFail();
            $member->fill(['sharing_mode' => $attributes['sharing_mode']]);
            $changed = $member->isDirty();
            if ($changed) {
                $member->save();
            }
            if ($member->sharing_mode === ProjectSharingMode::ALL_TASKS || array_key_exists('task_ids', $attributes)) {
                $changed = $this->replaceGrants($project, $member, $attributes['task_ids'] ?? []) || $changed;
            }
            if ($changed) {
                $this->recordActivity($project);
            }

            return $this->details($member);
        });
    }

    public function setGrants(User $owner, Project $project, ProjectCollaborator $member, array $taskIds): ProjectCollaborator
    {
        return DB::transaction(function () use ($owner, $project, $member, $taskIds): ProjectCollaborator {
            $project = $this->editableProject($owner, $project);
            $member = $project->collaborators()->whereKey($member->id)->lockForUpdate()->firstOrFail();
            if ($member->sharing_mode !== ProjectSharingMode::SELECTED_TASKS) {
                throw ValidationException::withMessages(['task_ids' => 'Task grants require selected_tasks sharing mode.']);
            }
            if ($this->replaceGrants($project, $member, $taskIds)) {
                $this->recordActivity($project);
            }

            return $this->details($member);
        });
    }

    public function remove(User $owner, Project $project, ProjectCollaborator $member): void
    {
        DB::transaction(function () use ($owner, $project, $member): void {
            $project = $this->editableProject($owner, $project);
            $member = $project->collaborators()->whereKey($member->id)->lockForUpdate()->firstOrFail();
            $member->delete(); // Membership grants cascade; task authorship/history remain.
            $this->recordActivity($project);
        });
    }

    private function editableProject(User $owner, Project $project): Project
    {
        $project = Project::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();
        Gate::forUser($owner)->authorize('manageSharing', $project);

        return $project;
    }

    private function replaceGrants(Project $project, ProjectCollaborator $member, array $taskIds): bool
    {
        if ($taskIds !== [] && $member->sharing_mode !== ProjectSharingMode::SELECTED_TASKS) {
            throw ValidationException::withMessages(['task_ids' => 'Task grants require selected_tasks sharing mode.']);
        }
        $taskIds = array_values(array_unique(array_map('intval', $taskIds)));
        sort($taskIds);
        // Revalidate after locking the project, including soft-deletion state.
        if ($project->tasks()->whereIn('id', $taskIds)->count() !== count($taskIds)) {
            throw ValidationException::withMessages(['task_ids' => 'Tasks must be non-deleted tasks from this project.']);
        }
        $existing = $member->taskGrants()->orderBy('task_id')->pluck('task_id')->map(fn ($id) => (int) $id)->all();
        if ($existing === $taskIds) {
            return false;
        }
        $member->taskGrants()->whereNotIn('task_id', $taskIds)->delete();
        foreach (array_diff($taskIds, $existing) as $taskId) {
            $member->taskGrants()->create(['project_id' => $project->id, 'task_id' => $taskId]);
        }

        return true;
    }

    private function recordActivity(Project $project): void
    {
        $project->forceFill(['last_activity_at' => now()])->save();
    }

    private function details(ProjectCollaborator $member): ProjectCollaborator
    {
        return $member->load(['user:id,username,avatar', 'taskGrants' => fn ($query) => $query->orderBy('task_id')]);
    }
}
