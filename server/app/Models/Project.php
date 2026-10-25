<?php

namespace App\Models;

use App\Enums\ProjectProgressType;
use App\Enums\ProjectStatus;
use App\Enums\ProjectTaskExecutionType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Project extends Model
{
    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $fillable = [
        'name', 'description', 'category_id', 'icon', 'progress_type',
        'default_execution_type', 'note_template',
    ];

    protected $attributes = [
        'icon' => 'folder',
        'progress_type' => 'completion',
        'default_execution_type' => 'one_time',
        'status' => 'active',
    ];

    protected $casts = [
        'status' => ProjectStatus::class,
        'progress_type' => ProjectProgressType::class,
        'default_execution_type' => ProjectTaskExecutionType::class,
        'last_activity_at' => 'immutable_datetime',
    ];

    public function scopeAccessibleTo(Builder $query, User $user): Builder
    {
        return $query->where(function (Builder $accessible) use ($user): void {
            $accessible->where('projects.created_by', $user->id)
                ->orWhereHas('collaborators', fn (Builder $members) => $members->where('user_id', $user->id));
        });
    }

    public function isOwnedBy(User $user): bool
    {
        return (int) $this->created_by === (int) $user->id;
    }

    public function collaborators(): HasMany
    {
        return $this->hasMany(ProjectCollaborator::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(ProjectTask::class);
    }

    public function notes(): HasMany
    {
        return $this->hasMany(ProjectProgressNote::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ProjectCategory::class, 'category_id');
    }

    public function statusPeriods(): HasMany
    {
        return $this->hasMany(ProjectStatusPeriod::class)->orderBy('starts_at')->orderBy('id');
    }
}
