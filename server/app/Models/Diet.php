<?php

  namespace App\Models;

  use App\Enums\DietStatus;
  use Illuminate\Database\Eloquent\Factories\HasFactory;
  use Illuminate\Database\Eloquent\Model;
  use Illuminate\Database\Eloquent\Relations\BelongsTo;
  use Illuminate\Database\Eloquent\Relations\HasMany;

  class Diet extends Model
  {
    use HasFactory;

    protected $casts = [
      'status' => DietStatus::class,
    ];

    protected $fillable = [
      'name',
      'description',
      'notes',
      'thumbnail',
      'status',
      'created_by'
    ];


    public function creator(): BelongsTo
    {
      return $this->belongsTo(User::class, 'created_by');
    }

    public function sources(): HasMany
    {
      return $this->hasMany(DietSource::class);
    }
  }
