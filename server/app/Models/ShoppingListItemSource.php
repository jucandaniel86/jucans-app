<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShoppingListItemSource extends Model
{
    protected $fillable = [
        'shopping_list_item_id',
        'recipe_id',
        'quantity',
        'unit',
    ];

    protected $casts = [
        'quantity' => 'decimal:3',
    ];

    public function shoppingListItem(): BelongsTo
    {
        return $this->belongsTo(ShoppingListItem::class);
    }

    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class);
    }
}
