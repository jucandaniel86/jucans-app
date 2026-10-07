<?php
  namespace App\Enums;

  enum DietRecipePriority: string
  {
    case NORMAL = 'normal';
    case HIGH = 'high';
    case REQUIRED = 'required';

    public static function values(): array
    {
      return array_column(self::cases(), 'value');
    }
  }
