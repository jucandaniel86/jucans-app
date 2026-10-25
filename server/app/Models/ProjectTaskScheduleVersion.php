<?php

namespace App\Models;

use App\Enums\ProjectRecurrenceType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectTaskScheduleVersion extends Model
{
    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $guarded = ['id'];

    protected $casts = [
        'recurrence_type' => ProjectRecurrenceType::class,
        'weekdays' => 'array',
        'version' => 'integer',
        'interval_days' => 'integer',
        'month_day' => 'integer',
    ];

    public function task(): BelongsTo
    {
        return $this->belongsTo(ProjectTask::class, 'task_id')->withTrashed();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
