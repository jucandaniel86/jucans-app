<?php

namespace App\Models;

use App\Enums\ProjectTaskCompletionAction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class ProjectTaskCompletionEvent extends Model
{
    public $timestamps = false;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $guarded = ['id'];

    protected $casts = ['action' => ProjectTaskCompletionAction::class, 'recorded_at' => 'immutable_datetime'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Completion history is append-only.'));
        static::deleting(fn () => throw new LogicException('Completion history is append-only.'));
    }

    public function occurrence(): BelongsTo
    {
        return $this->belongsTo(ProjectTaskOccurrence::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function reversedEvent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_event_id');
    }
}
