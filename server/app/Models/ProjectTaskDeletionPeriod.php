<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectTaskDeletionPeriod extends Model
{
    public $timestamps = false;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $guarded = ['id'];

    protected $casts = ['deleted_at' => 'immutable_datetime', 'restored_at' => 'immutable_datetime'];

    public function task(): BelongsTo
    {
        return $this->belongsTo(ProjectTask::class, 'task_id')->withTrashed();
    }
}
