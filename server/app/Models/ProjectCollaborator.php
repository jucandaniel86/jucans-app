<?php

namespace App\Models;

use App\Enums\ProjectSharingMode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProjectCollaborator extends Model
{
    protected $fillable = ['user_id', 'sharing_mode'];

    protected $casts = ['sharing_mode' => ProjectSharingMode::class];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function taskGrants(): HasMany
    {
        return $this->hasMany(ProjectTaskGrant::class, 'collaborator_id');
    }
}
