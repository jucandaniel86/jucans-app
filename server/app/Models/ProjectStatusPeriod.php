<?php

namespace App\Models;

use App\Enums\ProjectStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectStatusPeriod extends Model
{
    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $fillable = ['status', 'starts_at', 'ends_at', 'changed_by'];

    protected $casts = [
        'status' => ProjectStatus::class,
        'starts_at' => 'immutable_datetime',
        'ends_at' => 'immutable_datetime',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
