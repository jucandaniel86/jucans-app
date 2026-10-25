<?php

namespace App\Enums;

enum ProjectTaskCompletionAction: string
{
    case DONE = 'done';
    case UNDO = 'undo';
    case SKIP = 'skip';

    public function targetStatus(): ProjectTaskOccurrenceStatus
    {
        return match ($this) {
            self::DONE => ProjectTaskOccurrenceStatus::DONE,
            self::UNDO => ProjectTaskOccurrenceStatus::PENDING,
            self::SKIP => ProjectTaskOccurrenceStatus::SKIPPED,
        };
    }
}
