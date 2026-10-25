<?php

namespace App\Services;

use App\Enums\ProjectStatus;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class ProjectService
{
    public function create(User $user, array $attributes): Project
    {
        Gate::forUser($user)->authorize('create', Project::class);

        return DB::transaction(function () use ($user, $attributes): Project {
            $now = now();
            $project = new Project(Arr::except($attributes, ['status']));
            $project->forceFill([
                'created_by' => $user->id,
                'status' => $attributes['status'] ?? ProjectStatus::ACTIVE,
                'last_activity_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ])->save();
            $project->statusPeriods()->create([
                'status' => $project->status,
                'starts_at' => $now,
                'changed_by' => $user->id,
            ]);

            return $this->details($project);
        });
    }

    public function update(User $user, Project $project, array $attributes): Project
    {
        return DB::transaction(function () use ($user, $project, $attributes): Project {
            $project = Project::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($user)->authorize('update', $project);
            $project->fill($attributes);
            if ($project->isDirty()) {
                $project->forceFill(['last_activity_at' => now()])->save();
            }

            return $this->details($project);
        });
    }

    public function changeStatus(User $user, Project $project, ProjectStatus $status): Project
    {
        return DB::transaction(function () use ($user, $project, $status): Project {
            $project = Project::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($user)->authorize('changeStatus', $project);
            abort_unless($project->status->canTransitionTo($status), 409, 'Reactivate the project before changing its inactive status.');

            if ($project->status !== $status) {
                $now = now();
                // Every transition locks the parent first, serializing interval changes.
                $period = $project->statusPeriods()->whereNull('ends_at')->lockForUpdate()->sole();
                $period->update(['ends_at' => $now]);
                $project->statusPeriods()->create([
                    'status' => $status,
                    'starts_at' => $now,
                    'changed_by' => $user->id,
                ]);
                $project->forceFill(['status' => $status, 'last_activity_at' => $now])->save();
            }

            return $this->details($project);
        });
    }

    private function details(Project $project): Project
    {
        return $project->load(['category', 'owner:id,username,avatar', 'statusPeriods']);
    }
}
