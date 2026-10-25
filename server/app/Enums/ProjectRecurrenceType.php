<?php

namespace App\Enums;

enum ProjectRecurrenceType: string
{
    case DAILY = 'daily';
    case EVERY_N_DAYS = 'every_n_days';
    case WEEKDAYS = 'weekdays';
    case MONTHLY = 'monthly';
}
