<?php

  namespace App\Models;

  use App\Enums\DietSourceType;
  use Illuminate\Database\Eloquent\Factories\HasFactory;
  use Illuminate\Database\Eloquent\Model;

  class DietSource extends Model
  {
    use HasFactory;

    protected $casts = [
      'type' => DietSourceType::class,
    ];

    protected $fillable = [
      'type',
      'is_official',
      'title',
      'url',
      'notes',
    ];
  }
