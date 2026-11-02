<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectCustomFieldOption extends Model
{
    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $fillable = ['label', 'sort_order'];

    protected $casts = ['sort_order' => 'integer', 'archived_at' => 'immutable_datetime'];

    public function field(): BelongsTo
    {
        return $this->belongsTo(ProjectCustomField::class, 'field_id');
    }
}
