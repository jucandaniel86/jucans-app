<?php

namespace App\Models;

use App\Support\NameNormalizer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IngredientAlias extends Model
{
    protected $fillable = [
        'ingredient_id',
        'alias',
    ];

    protected static function booted(): void
    {
        static::saving(function (IngredientAlias $alias): void {
            $alias->normalized_alias = NameNormalizer::normalize($alias->alias);
        });
    }

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }
}
