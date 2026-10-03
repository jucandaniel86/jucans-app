<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ShoppingCategory extends Model
{
    protected $fillable = [
        'name',
        'emoji',
        'sort_order',
    ];

    protected $casts = [
        'sort_order' => 'integer',
    ];

    public function ingredients(): HasMany
    {
        return $this->hasMany(Ingredient::class);
    }

    public function shoppingListItems(): HasMany
    {
        return $this->hasMany(ShoppingListItem::class);
    }
}
