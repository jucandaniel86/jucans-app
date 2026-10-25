<?php

namespace App\Policies;

use App\Enums\ProjectStatus;
use App\Models\Project;
use App\Models\User;

class ProjectPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function view(User $user, Project $project): bool
    {
        return $project->isOwnedBy($user) || $project->collaborators()->where('user_id', $user->id)->exists();
    }

    public function update(User $user, Project $project): bool
    {
        return $project->isOwnedBy($user) && $project->status === ProjectStatus::ACTIVE;
    }

    public function changeStatus(User $user, Project $project): bool
    {
        return $project->isOwnedBy($user);
    }

    public function viewCollaborators(User $user, Project $project): bool
    {
        return $project->isOwnedBy($user);
    }

    public function manageSharing(User $user, Project $project): bool
    {
        return $project->isOwnedBy($user) && $project->status === ProjectStatus::ACTIVE;
    }
}
