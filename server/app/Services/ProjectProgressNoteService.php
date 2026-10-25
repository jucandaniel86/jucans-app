<?php

namespace App\Services;

use App\Models\Project;
use App\Models\ProjectProgressNote;
use App\Models\ProjectTaskEvaluation;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ProjectProgressNoteService
{
    public function relations(User $user, bool $trash = false): array
    {
        return [
            'tasks' => fn ($query) => $query->orderBy('project_tasks.id'),
            'evaluations' => function ($query) use ($user, $trash): void {
                if (! $trash) {
                    $query->active()->visibleTo($user);
                }
                $query->orderBy('evaluation_date')->orderBy('id');
            },
        ];
    }

    public function create(User $user, Project $project, array $input): ProjectProgressNote
    {
        return DB::transaction(function () use ($user, $project, $input): ProjectProgressNote {
            $project = Project::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($user)->authorize('create', [ProjectProgressNote::class, $project]);
            $taskIds = $this->accessibleTaskIds($user, $project, $input['task_ids'] ?? []);
            $note = new ProjectProgressNote(Arr::only($input, ['title', 'content', 'noted_at']));
            $note->forceFill(['project_id' => $project->id, 'created_by' => $user->id])->save();
            $this->syncTasks($project, $note, $taskIds);
            $this->activity($project);

            return $note->refresh()->load($this->relations($user));
        });
    }

    public function update(User $user, Project $project, ProjectProgressNote $note, array $input): ProjectProgressNote
    {
        return DB::transaction(function () use ($user, $project, $note, $input): ProjectProgressNote {
            $project = Project::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();
            $note = $this->lockedNote($project, $note);
            Gate::forUser($user)->authorize('update', $note);
            $changed = false;
            if (array_key_exists('task_ids', $input)) {
                $ids = $this->accessibleTaskIds($user, $project, $input['task_ids']);
                $existing = $note->tasks()->orderBy('project_tasks.id')->pluck('project_tasks.id')->map(fn ($id) => (int) $id)->all();
                if ($existing !== $ids) {
                    abort_if($note->evaluations()->whereNotIn('task_id', $ids)->exists(), 409, 'Keep task associations referenced by evaluation history.');
                    $this->syncTasks($project, $note, $ids);
                    $changed = true;
                }
            }
            $note->fill(Arr::only($input, ['title', 'content', 'noted_at']));
            if ($note->isDirty() || $changed) {
                $note->forceFill(['updated_by' => $user->id])->save();
                $this->activity($project);
            }

            return $note->load($this->relations($user));
        });
    }

    public function delete(User $user, Project $project, ProjectProgressNote $note): void
    {
        DB::transaction(function () use ($user, $project, $note): void {
            $project = Project::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();
            $note = $this->lockedNote($project, $note);
            Gate::forUser($user)->authorize('delete', $note);
            foreach ($note->evaluations()->where('active_slot', '1')->orderBy('id')->lockForUpdate()->get() as $evaluation) {
                $evaluation->fill(['active_slot' => null, 'lock_version' => $evaluation->lock_version + 1])->save();
            }
            $note->forceFill(['updated_by' => $user->id])->save();
            $note->delete();
            $this->activity($project);
        });
    }

    public function restore(User $user, Project $project, ProjectProgressNote $note): ProjectProgressNote
    {
        return DB::transaction(function () use ($user, $project, $note): ProjectProgressNote {
            $project = Project::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();
            $note = $this->lockedNote($project, $note, true);
            Gate::forUser($user)->authorize('restore', $note);
            $evaluations = $note->evaluations()->whereNull('withdrawn_at')->orderBy('id')->lockForUpdate()->get();
            $seen = [];
            foreach ($evaluations as $evaluation) {
                $key = $evaluation->task_id.':'.$evaluation->evaluation_date;
                abort_if(isset($seen[$key]) || ProjectTaskEvaluation::query()->where('task_id', $evaluation->task_id)
                    ->where('evaluation_date', $evaluation->evaluation_date)->where('active_slot', '1')->where('id', '!=', $evaluation->id)->exists(),
                    409, 'An active evaluation already exists for a linked task/date. Withdraw it explicitly before restoring this note.');
                $seen[$key] = true;
            }
            $note->forceFill(['updated_by' => $user->id])->restore();
            foreach ($evaluations as $evaluation) {
                $evaluation->fill(['active_slot' => '1', 'lock_version' => $evaluation->lock_version + 1])->save();
            }
            $this->activity($project);

            return $note->load($this->relations($user));
        });
    }

    public function accessibleTaskIds(User $user, Project $project, array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids);
        if ($project->tasks()->visibleTo($user)->whereIn('project_tasks.id', $ids)->count() !== count($ids)) {
            throw ValidationException::withMessages(['task_ids' => 'All tasks must be accessible non-deleted tasks from this project.']);
        }

        return $ids;
    }

    private function syncTasks(Project $project, ProjectProgressNote $note, array $ids): void
    {
        $note->tasks()->sync(array_fill_keys($ids, ['project_id' => $project->id]));
    }

    private function lockedNote(Project $project, ProjectProgressNote $note, bool $deleted = false): ProjectProgressNote
    {
        $query = $project->notes();
        if ($deleted) {
            $query->onlyTrashed();
        }

        return $query->whereKey($note->id)->lockForUpdate()->firstOrFail()->setRelation('project', $project);
    }

    private function activity(Project $project): void
    {
        $project->forceFill(['last_activity_at' => now()])->save();
    }
}
