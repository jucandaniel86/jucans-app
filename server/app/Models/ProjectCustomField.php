<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProjectCustomField extends Model
{
    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $fillable = ['name', 'key', 'description', 'type', 'unit', 'min', 'max', 'step', 'aggregation'];

    protected $casts = ['min' => 'decimal:6', 'max' => 'decimal:6', 'step' => 'decimal:6',
        'archived_at' => 'immutable_datetime', 'first_value_at' => 'immutable_datetime'];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function options(): HasMany
    {
        return $this->hasMany(ProjectCustomFieldOption::class, 'field_id')->orderBy('sort_order')->orderBy('id');
    }

    public function associations(): HasMany
    {
        return $this->hasMany(ProjectNoteTypeField::class, 'field_id');
    }
}
