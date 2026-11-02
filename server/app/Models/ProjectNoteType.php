<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProjectNoteType extends Model
{
    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $fillable = ['name', 'description', 'template'];

    protected $casts = ['is_default' => 'boolean', 'archived_at' => 'immutable_datetime'];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function fieldHistory(): HasMany
    {
        return $this->hasMany(ProjectNoteTypeField::class, 'note_type_id');
    }

    public function fields(): HasMany
    {
        return $this->fieldHistory()->where('active_slot', '1')->orderBy('sort_order')->orderBy('id');
    }
}
