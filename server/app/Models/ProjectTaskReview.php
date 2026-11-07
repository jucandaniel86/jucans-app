<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectTaskReview extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = ['user_id' => 'integer'];

    public function occurrence(): BelongsTo
    {
        return $this->belongsTo(ProjectTaskOccurrence::class, 'occurrence_id');
    }

    public function note(): BelongsTo
    {
        return $this->belongsTo(ProjectProgressNote::class, 'note_id')->withTrashed();
    }
}
