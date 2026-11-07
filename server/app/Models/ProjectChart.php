<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class ProjectChart extends Model
{
    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $fillable = ['title', 'type', 'priority', 'active', 'total', 'y_min', 'y_max', 'pie_aggregation', 'pie_value_field_id'];

    protected $casts = ['pie_value_field_id' => 'integer', 'priority' => 'integer', 'active' => 'boolean', 'total' => 'decimal:6', 'y_min' => 'decimal:6', 'y_max' => 'decimal:6'];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function pieValueField(): BelongsTo
    {
        return $this->belongsTo(ProjectCustomField::class, 'pie_value_field_id');
    }

    public function fields(): BelongsToMany
    {
        return $this->belongsToMany(ProjectCustomField::class, 'project_chart_fields', 'chart_id', 'field_id')
            ->withPivot(['project_id', 'position'])->orderBy('project_chart_fields.position')->orderBy('project_custom_fields.id');
    }
}
