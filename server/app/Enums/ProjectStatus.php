<?php

namespace App\Enums;

enum ProjectStatus: string
{
    case ACTIVE = 'active';
    case COMPLETED = 'completed';
    case ARCHIVED = 'archived';

    public function canTransitionTo(self $next): bool
    {
        return $this === $next || $this === self::ACTIVE || $next === self::ACTIVE;
    }
}
