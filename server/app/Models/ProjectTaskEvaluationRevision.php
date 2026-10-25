<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class ProjectTaskEvaluationRevision extends Model
{
    public $timestamps = false;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $guarded = ['id'];

    protected $casts = ['previous_score' => 'integer', 'new_score' => 'integer', 'recorded_at' => 'immutable_datetime'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Evaluation revisions are append-only.'));
        static::deleting(fn () => throw new LogicException('Evaluation revisions are append-only.'));
    }

    public function evaluation(): BelongsTo
    {
        return $this->belongsTo(ProjectTaskEvaluation::class);
    }
}
