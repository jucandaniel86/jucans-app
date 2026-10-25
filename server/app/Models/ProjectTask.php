<?php

namespace App\Models;

use App\Enums\ProjectSharingMode;
use App\Enums\ProjectStatus;
use App\Enums\ProjectTaskExecutionType;
use App\Enums\ProjectTaskPriority;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProjectTask extends Model
{
    use SoftDeletes;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $fillable = [
        'title', 'description', 'execution_type', 'priority', 'status',
        'scheduled_date', 'scheduled_time', 'due_date', 'due_time', 'show_in_daily_widget',
    ];

    protected $attributes = ['priority' => 'medium', 'status' => 'active', 'show_in_daily_widget' => true];

    protected $casts = [
        'execution_type' => ProjectTaskExecutionType::class,
        'priority' => ProjectTaskPriority::class,
        'status' => ProjectStatus::class,
        'show_in_daily_widget' => 'boolean',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function grants(): HasMany
    {
        return $this->hasMany(ProjectTaskGrant::class, 'task_id');
    }

    public function occurrences(): HasMany
    {
        return $this->hasMany(ProjectTaskOccurrence::class, 'task_id');
    }

    public function scheduleVersions(): HasMany
    {
        return $this->hasMany(ProjectTaskScheduleVersion::class, 'task_id')->orderBy('version');
    }

    public function deletionPeriods(): HasMany
    {
        return $this->hasMany(ProjectTaskDeletionPeriod::class, 'task_id')->orderBy('deleted_at');
    }

    public function progressNotes(): BelongsToMany
    {
        return $this->belongsToMany(ProjectProgressNote::class, 'project_progress_note_tasks', 'task_id', 'note_id');
    }

    public function evaluations(): HasMany
    {
        return $this->hasMany(ProjectTaskEvaluation::class, 'task_id');
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $query->whereHas('project', function (Builder $project) use ($user): void {
            $project->where('created_by', $user->id)
                ->orWhereHas('collaborators', function (Builder $member) use ($user): void {
                    $member->where('user_id', $user->id)->where(function (Builder $access) use ($user): void {
                        $access->where('sharing_mode', ProjectSharingMode::ALL_TASKS->value)
                            ->orWhere('project_tasks.created_by', $user->id)
                            ->orWhereHas('taskGrants', fn (Builder $grants) => $grants->whereColumn('task_id', 'project_tasks.id'));
                    });
                });
        });
    }
}
