<?php

  namespace App\Enums;

  enum DietStatus: string
  {
    case DRAFT = 'draft';
    case COMPLETED = 'completed';
    case ACTIVE = 'active';

    public static function values(): array
    {
      return array_column(self::cases(), 'value');
    }
  }
