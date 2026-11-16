<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProjectNoteType extends Model
{
    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $fillable = ['name', 'description', 'template'];

    protected $casts = ['is_default' => 'boolean', 'is_shopping' => 'boolean',
        'shopping_amount_field_id' => 'integer', 'shopping_provider_field_id' => 'integer', 'shopping_provider_option_id' => 'integer', 'archived_at' => 'immutable_datetime'];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    // Read-side equivalent of the Shopping mapping checks; evaluated before pagination.
    public function scopeShoppingConfigured(Builder $query): Builder
    {
        $query->where('project_note_types.is_shopping', '1')->whereNull('project_note_types.archived_at');
        foreach (['shopping_amount_field_id' => 'number', 'shopping_provider_field_id' => 'select'] as $column => $type) {
            $query->whereExists(function ($fields) use ($column, $type) {
                $fields->selectRaw('1')->from('project_custom_fields as shopping_field')
                    ->join('project_note_type_fields as shopping_attachment', 'shopping_attachment.field_id', '=', 'shopping_field.id')
                    ->whereColumn('shopping_field.id', "project_note_types.{$column}")
                    ->whereColumn('shopping_field.project_id', 'project_note_types.project_id')
                    ->where('shopping_field.type', $type)->whereNull('shopping_field.archived_at')
                    ->whereColumn('shopping_attachment.note_type_id', 'project_note_types.id')
                    ->where('shopping_attachment.active_slot', '1');
            });
        }
        return $query->whereExists(function ($options) {
            $options->selectRaw('1')->from('project_custom_field_options as shopping_option')
                ->whereColumn('shopping_option.id', 'project_note_types.shopping_provider_option_id')
                ->whereColumn('shopping_option.field_id', 'project_note_types.shopping_provider_field_id')
                ->whereNull('shopping_option.archived_at');
        });
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
