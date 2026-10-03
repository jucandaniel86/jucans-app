<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ShoppingListItem extends Model
{
    protected $fillable = [
        'shopping_list_id',
        'ingredient_id',
        'name',
        'calculated_quantity',
        'quantity',
        'unit',
        'shopping_category_id',
        'is_checked',
        'quantity_overridden',
    ];

    protected $casts = [
        'calculated_quantity' => 'decimal:3',
        'quantity' => 'decimal:3',
        'is_checked' => 'boolean',
        'quantity_overridden' => 'boolean',
    ];

    public function shoppingList(): BelongsTo
    {
        return $this->belongsTo(ShoppingList::class);
    }

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }

    public function shoppingCategory(): BelongsTo
    {
        return $this->belongsTo(ShoppingCategory::class);
    }

    public function sources(): HasMany
    {
        return $this->hasMany(ShoppingListItemSource::class);
    }
}
