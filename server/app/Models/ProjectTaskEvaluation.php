<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProjectTaskEvaluation extends Model
{
    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $guarded = ['id'];

    protected $attributes = ['lock_version' => 0, 'active_slot' => '1'];

    protected $casts = ['score' => 'integer', 'lock_version' => 'integer', 'active_slot' => 'integer', 'withdrawn_at' => 'immutable_datetime'];

    public function task(): BelongsTo
    {
        return $this->belongsTo(ProjectTask::class, 'task_id')->withTrashed();
    }

    public function note(): BelongsTo
    {
        return $this->belongsTo(ProjectProgressNote::class, 'note_id');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(ProjectTaskEvaluationRevision::class, 'evaluation_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active_slot', '1')->whereNull('withdrawn_at')
            ->whereHas('task', fn (Builder $task) => $task->whereNull('project_tasks.deleted_at'))
            ->where(fn (Builder $note) => $note->whereNull('note_id')->orWhereHas('note'));
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $query->whereIn('task_id', ProjectTask::query()->visibleTo($user)->select('project_tasks.id'))
            ->where(fn (Builder $note) => $note->whereNull('note_id')->orWhereHas('note', fn (Builder $notes) => $notes->visibleTo($user)));
    }
}
