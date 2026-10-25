<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectTaskGrant extends Model
{
    protected $fillable = ['project_id', 'task_id'];

    public function collaborator(): BelongsTo
    {
        return $this->belongsTo(ProjectCollaborator::class);
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(ProjectTask::class, 'task_id')->withTrashed();
    }
}
