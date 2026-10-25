<?php

namespace App\Models;

use App\Enums\ProjectTaskOccurrenceStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProjectTaskOccurrence extends Model
{
    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $guarded = ['id'];

    protected $attributes = ['status' => 'pending', 'lock_version' => 0];

    protected $casts = [
        'status' => ProjectTaskOccurrenceStatus::class,
        'completed_at' => 'immutable_datetime',
        'lock_version' => 'integer',
    ];

    public function task(): BelongsTo
    {
        return $this->belongsTo(ProjectTask::class, 'task_id')->withTrashed();
    }

    public function events(): HasMany
    {
        return $this->hasMany(ProjectTaskCompletionEvent::class, 'occurrence_id');
    }

    public function scheduleVersion(): BelongsTo
    {
        return $this->belongsTo(ProjectTaskScheduleVersion::class);
    }
}
