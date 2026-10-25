<?php

namespace App\Enums;

enum ProjectSharingMode: string
{
    case ALL_TASKS = 'all_tasks';
    case SELECTED_TASKS = 'selected_tasks';
}
