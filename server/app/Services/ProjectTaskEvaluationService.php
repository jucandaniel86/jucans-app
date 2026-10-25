<?php

namespace App\Services;

use App\Models\Project;
use App\Models\ProjectProgressNote;
use App\Models\ProjectTask;
use App\Models\ProjectTaskEvaluation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ProjectTaskEvaluationService
{
    public function create(User $user, Project $project, ProjectTask $task, array $input): ProjectTaskEvaluation
    {
        return DB::transaction(function () use ($user, $project, $task, $input): ProjectTaskEvaluation {
            $project = Project::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();
            $task = $project->tasks()->whereKey($task->id)->lockForUpdate()->firstOrFail()->setRelation('project', $project);
            Gate::forUser($user)->authorize('create', [ProjectTaskEvaluation::class, $task]);
            $noteId = $input['note_id'] ?? null;
            $this->validateNote($user, $project, $task, $noteId);
            abort_if($task->evaluations()->where('evaluation_date', $input['evaluation_date'])->where('active_slot', '1')->exists(),
                409, 'An evaluation already exists. Use an explicit PATCH with its current lock_version.');
            $evaluation = $task->evaluations()->create([
                'project_id' => $project->id,
                'evaluation_date' => $input['evaluation_date'],
                'score' => $input['score'],
                'created_by' => $user->id,
                'note_id' => $noteId,
            ]);
            $this->revision($user, $evaluation, null, null);
            $this->activity($project);

            return $evaluation->refresh();
        });
    }

    public function update(User $user, Project $project, ProjectTask $task, ProjectTaskEvaluation $evaluation, array $input, bool $withdraw = false): ProjectTaskEvaluation
    {
        return DB::transaction(function () use ($user, $project, $task, $evaluation, $input, $withdraw): ProjectTaskEvaluation {
            $project = Project::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();
            $task = $project->tasks()->whereKey($task->id)->lockForUpdate()->firstOrFail()->setRelation('project', $project);
            $evaluation = $task->evaluations()->whereKey($evaluation->id)->lockForUpdate()->firstOrFail()->setRelation('task', $task);
            Gate::forUser($user)->authorize('update', $evaluation);
            abort_unless($evaluation->lock_version === (int) $input['lock_version'], 409, 'The evaluation changed. Reload its lock_version.');
            $oldScore = $evaluation->score;
            $oldNote = $evaluation->note_id;
            if ($withdraw) {
                $evaluation->fill(['withdrawn_at' => now('UTC'), 'active_slot' => null]);
            } else {
                $noteId = array_key_exists('note_id', $input) ? $input['note_id'] : $evaluation->note_id;
                $this->validateNote($user, $project, $task, $noteId);
                $evaluation->fill(['score' => $input['score'] ?? $evaluation->score, 'note_id' => $noteId]);
            }
            if ($evaluation->isDirty()) {
                $evaluation->lock_version++;
                $evaluation->save();
                $this->revision($user, $evaluation, $oldScore, $oldNote);
                $this->activity($project);
            }

            return $evaluation;
        });
    }

    private function validateNote(User $user, Project $project, ProjectTask $task, ?int $noteId): void
    {
        if ($noteId !== null && ! ProjectProgressNote::query()->where('project_id', $project->id)->whereKey($noteId)
            ->visibleTo($user)->whereHas('tasks', fn ($query) => $query->where('project_tasks.id', $task->id))->exists()) {
            throw ValidationException::withMessages(['note_id' => 'The note must be visible, active, and associated with this task in the same project.']);
        }
    }

    private function revision(User $user, ProjectTaskEvaluation $evaluation, ?int $previousScore, ?int $previousNote): void
    {
        $evaluation->revisions()->create([
            'actor_id' => $user->id,
            'previous_score' => $previousScore,
            'new_score' => $evaluation->withdrawn_at === null ? $evaluation->score : null,
            'previous_note_id' => $previousNote,
            'new_note_id' => $evaluation->note_id,
            'recorded_at' => now('UTC'),
        ]);
    }

    private function activity(Project $project): void
    {
        $project->forceFill(['last_activity_at' => now()])->save();
    }
}
