<?php

namespace App\Models;

use App\Support\NameNormalizer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

class Ingredient extends Model
{
    protected $fillable = [
        'name',
        'default_unit',
        'shopping_category_id',
        'is_shoppable',
    ];

    protected $casts = [
        'is_shoppable' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::saving(function (Ingredient $ingredient): void {
            if ($ingredient->default_unit !== null && ! array_key_exists($ingredient->default_unit, config('food.units'))) {
                throw ValidationException::withMessages([
                    'default_unit' => 'The selected default unit is invalid.',
                ]);
            }

            $ingredient->normalized_name = NameNormalizer::normalize($ingredient->name);
        });
    }

    public function recipeIngredients(): HasMany
    {
        return $this->hasMany(RecipeIngredient::class);
    }

    public function aliases(): HasMany
    {
        return $this->hasMany(IngredientAlias::class);
    }

    public function shoppingCategory(): BelongsTo
    {
        return $this->belongsTo(ShoppingCategory::class);
    }

    public function recipes(): BelongsToMany
    {
        return $this->belongsToMany(Recipe::class, 'recipe_ingredients')
            ->withPivot(['id', 'value', 'unit', 'raw_text'])
            ->withTimestamps();
    }

    public function shoppingListItems(): HasMany
    {
        return $this->hasMany(ShoppingListItem::class);
    }
}
