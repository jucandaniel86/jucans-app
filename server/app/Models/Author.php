<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Author extends Model
{
    protected $fillable = ['name', 'youtube_link', 'website_link', 'icon'];

    public function recipes(): HasMany
    {
        return $this->hasMany(Recipe::class);
    }
}
