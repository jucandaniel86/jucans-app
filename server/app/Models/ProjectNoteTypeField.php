<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectNoteTypeField extends Model
{
    public $timestamps = false;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $guarded = ['id'];

    protected $casts = ['required' => 'boolean', 'sort_order' => 'integer',
        'activated_at' => 'immutable_datetime', 'deactivated_at' => 'immutable_datetime'];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function noteType(): BelongsTo
    {
        return $this->belongsTo(ProjectNoteType::class, 'note_type_id');
    }

    public function field(): BelongsTo
    {
        return $this->belongsTo(ProjectCustomField::class, 'field_id');
    }
}
