<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProjectCategory extends Model
{
    protected $fillable = ['name', 'slug', 'sort_order'];

    protected $casts = ['sort_order' => 'integer'];

    public function projects(): HasMany
    {
        return $this->hasMany(Project::class, 'category_id');
    }
}
