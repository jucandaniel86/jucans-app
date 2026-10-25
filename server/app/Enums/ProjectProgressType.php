<?php

namespace App\Enums;

enum ProjectProgressType: string
{
    case COMPLETION = 'completion';
    case DAILY_COMPLETION = 'daily_completion';
    case SCORED = 'scored';
}
