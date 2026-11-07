<?php

namespace App\Policies;

use App\Enums\ProjectStatus;
use App\Models\ProjectTask;
use App\Models\ProjectTaskEvaluation;
use App\Models\ProjectTaskReview;
use App\Models\User;

class ProjectTaskEvaluationPolicy
{
    public function view(User $user, ProjectTaskEvaluation $evaluation): bool
    {
        return ProjectTaskEvaluation::query()->visibleTo($user)->whereKey($evaluation->id)->exists();
    }

    public function create(User $user, ProjectTask $task): bool
    {
        return ! $task->trashed() && $task->project->status === ProjectStatus::ACTIVE && $user->can('view', $task);
    }

    public function update(User $user, ProjectTaskEvaluation $evaluation): bool
    {
        return $evaluation->active_slot === 1 && $evaluation->withdrawn_at === null
            && $evaluation->task->project->status === ProjectStatus::ACTIVE
            && ($evaluation->task->project->isOwnedBy($user) || (int) $evaluation->created_by === (int) $user->id)
            && ! ProjectTaskReview::query()->where('note_id', $evaluation->note_id)->where('user_id', '!=', $user->id)->exists()
            && $this->view($user, $evaluation);
    }
}
