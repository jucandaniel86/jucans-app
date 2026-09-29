<?php

namespace App\Models;

use App\Support\NameNormalizer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class FoodTag extends Model
{
    protected $fillable = [
        'name',
        'emoji',
    ];

    protected static function booted(): void
    {
        static::saving(function (FoodTag $tag): void {
            $tag->normalized_name = NameNormalizer::normalize($tag->name);
        });
    }

    public function recipes(): BelongsToMany
    {
        return $this->belongsToMany(Recipe::class)->withTimestamps();
    }
}
