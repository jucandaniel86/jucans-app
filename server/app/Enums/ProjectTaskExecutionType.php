<?php

namespace App\Enums;

enum ProjectTaskExecutionType: string
{
    case ONE_TIME = 'one_time';
    case RECURRING = 'recurring';
    case PERSISTENT = 'persistent';
}
