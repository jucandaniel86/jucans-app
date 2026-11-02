<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectProgressNoteValue extends Model
{
    protected $guarded = ['id'];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    // number_value is already a canonical decimal string; never pass through a float.
    protected $casts = ['boolean_value' => 'boolean', 'select_option_id' => 'integer'];

    public function field(): BelongsTo
    {
        return $this->belongsTo(ProjectCustomField::class, 'field_id');
    }

    public function typedValue(): string|bool|int|null
    {
        return match ($this->value_type) {
            'number' => $this->number_value === null ? null : (string) $this->number_value,
            'text' => $this->text_value,
            'boolean' => $this->boolean_value,
            'select' => $this->select_option_id,
        };
    }
}
