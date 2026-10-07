<?php
  namespace App\Enums;

  enum DietSourceType: string
  {
    case WEBSITE = 'website';
    case YOUTUBE = 'youtube';
    case ARTICLE = 'article';
    case BOOK = 'book';
    case OTHER = 'other';

    public static function values(): array
    {
      return array_column(self::cases(), 'value');
    }
  }
