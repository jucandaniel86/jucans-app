<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ShoppingList extends Model
{
    protected $fillable = [
        'name',
        'status',
        'created_by',
        'visibility',
        'closed_at',
    ];

    protected $casts = [
        'closed_at' => 'datetime',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'shopping_list_users')->withTimestamps();
    }

    public function recipes(): BelongsToMany
    {
        return $this->belongsToMany(Recipe::class, 'shopping_list_recipes')
            ->withPivot(['id', 'added_by'])
            ->withTimestamps();
    }

    public function items(): HasMany
    {
        return $this->hasMany(ShoppingListItem::class);
    }
}
