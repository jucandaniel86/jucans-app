<?php
  namespace App\Enums;

  enum DietIngredientStatus: string
  {
    case REQUIRED = 'required';
    case PREFERRED = 'preferred';
    case ALLOWED = 'allowed';
    case CONDITIONAL = 'conditional';
    case EXCLUDED = 'excluded';

    public static function values(): array
    {
      return array_column(self::cases(), 'value');
    }
  }
