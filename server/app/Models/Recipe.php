<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Recipe extends Model
{
    protected $fillable = [
        'name',
        'description',
        'image',
        'url',
        'consumption_days',
        'author_id',
    ];

    protected $attributes = ['consumption_days' => 1];

    protected $casts = ['consumption_days' => 'integer'];

    public function dailyStructures(): BelongsToMany
    {
        return $this->belongsToMany(DailyStructure::class, 'recipe_daily_structure')->withTimestamps();
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(Author::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(FoodTag::class)->withTimestamps();
    }

    public function recipeIngredients(): HasMany
    {
        return $this->hasMany(RecipeIngredient::class);
    }

    public function ingredients(): BelongsToMany
    {
        return $this->belongsToMany(Ingredient::class, 'recipe_ingredients')
            ->withPivot(['id', 'value', 'unit', 'raw_text'])
            ->withTimestamps();
    }

    public function shoppingLists(): BelongsToMany
    {
        return $this->belongsToMany(ShoppingList::class, 'shopping_list_recipes')
            ->withPivot(['id', 'added_by'])
            ->withTimestamps();
    }

    public function shoppingListItemSources(): HasMany
    {
        return $this->hasMany(ShoppingListItemSource::class);
    }
}
