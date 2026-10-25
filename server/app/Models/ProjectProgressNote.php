<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProjectProgressNote extends Model
{
    use SoftDeletes;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $fillable = ['title', 'content', 'noted_at'];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function tasks(): BelongsToMany
    {
        return $this->belongsToMany(ProjectTask::class, 'project_progress_note_tasks', 'note_id', 'task_id')
            ->withPivot('project_id')->withTrashed();
    }

    public function evaluations(): HasMany
    {
        return $this->hasMany(ProjectTaskEvaluation::class, 'note_id');
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        $accessibleTasks = ProjectTask::query()->visibleTo($user)->select('project_tasks.id');

        return $query->whereHas('project', fn (Builder $project) => $project->accessibleTo($user))
            ->where(function (Builder $visibility) use ($user, $accessibleTasks): void {
                $visibility->whereHas('project', fn (Builder $project) => $project->where('created_by', $user->id))
                    ->orWhere(function (Builder $collaborator) use ($user, $accessibleTasks): void {
                        $collaborator->where(function (Builder $kind) use ($user): void {
                            $kind->whereHas('tasks')->orWhere('project_progress_notes.created_by', $user->id);
                        })->whereDoesntHave('tasks', fn (Builder $tasks) => $tasks->whereNotIn('project_tasks.id', $accessibleTasks));
                    });
            });
    }
}
