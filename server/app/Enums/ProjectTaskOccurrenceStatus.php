<?php

namespace App\Enums;

enum ProjectTaskOccurrenceStatus: string
{
    case PENDING = 'pending';
    case DONE = 'done';
    case SKIPPED = 'skipped';
}
