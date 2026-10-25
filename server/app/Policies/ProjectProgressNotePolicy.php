<?php

namespace App\Policies;

use App\Enums\ProjectStatus;
use App\Models\Project;
use App\Models\ProjectProgressNote;
use App\Models\User;

class ProjectProgressNotePolicy
{
    public function viewAny(User $user, Project $project): bool
    {
        return $user->can('view', $project);
    }

    public function create(User $user, Project $project): bool
    {
        return $project->status === ProjectStatus::ACTIVE && $user->can('view', $project);
    }

    public function view(User $user, ProjectProgressNote $note): bool
    {
        return ProjectProgressNote::withTrashed()->visibleTo($user)->whereKey($note->id)->exists();
    }

    public function update(User $user, ProjectProgressNote $note): bool
    {
        return ! $note->trashed() && $this->manage($user, $note);
    }

    public function delete(User $user, ProjectProgressNote $note): bool
    {
        return $this->update($user, $note);
    }

    public function restore(User $user, ProjectProgressNote $note): bool
    {
        return $note->trashed() && $this->manage($user, $note);
    }

    public function viewTrash(User $user, Project $project): bool
    {
        return $project->isOwnedBy($user);
    }

    private function manage(User $user, ProjectProgressNote $note): bool
    {
        return $note->project->status === ProjectStatus::ACTIVE
            && ($note->project->isOwnedBy($user) || (int) $note->created_by === (int) $user->id)
            && $this->view($user, $note);
    }
}
