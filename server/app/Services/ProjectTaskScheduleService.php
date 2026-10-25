<?php

namespace App\Services;

use App\Models\Project;
use App\Models\ProjectTask;
use App\Models\ProjectTaskScheduleVersion;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ProjectTaskScheduleService
{
    public function save(User $user, Project $project, ProjectTask $task, array $input, bool $creating): ProjectTaskScheduleVersion
    {
        return DB::transaction(function () use ($user, $project, $task, $input, $creating): ProjectTaskScheduleVersion {
            $project = Project::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();
            $task = $project->tasks()->whereKey($task->id)->lockForUpdate()->firstOrFail()->setRelation('project', $project);
            Gate::forUser($user)->authorize('configureSchedule', $task);
            $previous = $task->scheduleVersions()->reorder()->orderByDesc('version')->lockForUpdate()->first();
            abort_if($creating && $previous !== null, 409, 'A schedule exists. Update its future version instead.');
            abort_if(! $creating && $previous === null, 404, 'Create the initial schedule first.');
            $timezone = $project->getAttributes()['timezone'] ?? config('app.timezone');
            $effective = Carbon::now($timezone)->startOfDay();
            if ($previous !== null) {
                $effective->addDay();
            }
            $from = $effective->toDateString();
            abort_if($previous !== null && $from < $previous->effective_from, 409, 'The new effective date cannot precede the latest version.');
            if (isset($input['end_date'], $input['start_date']) && $input['end_date'] < $input['start_date']) {
                throw ValidationException::withMessages(['end_date' => 'The end date must not precede the start date.']);
            }
            if ($previous !== null) {
                // Repeated same-day edits leave an empty superseded interval, not an overlap.
                $previous->update(['effective_until' => $from]);
            }
            $version = $task->scheduleVersions()->create([
                'version' => ($previous?->version ?? 0) + 1,
                'effective_from' => $from,
                'effective_until' => null,
                'recurrence_type' => $input['recurrence_type'],
                'interval_days' => $input['interval_days'] ?? null,
                'weekdays' => isset($input['weekdays']) ? array_values(array_map('intval', $input['weekdays'])) : null,
                'month_day' => $input['month_day'] ?? null,
                'anchor_date' => $input['start_date'] ?? $previous?->anchor_date ?? $from,
                'start_date' => $input['start_date'] ?? null,
                'end_date' => $input['end_date'] ?? null,
                'scheduled_time' => isset($input['scheduled_time']) ? $input['scheduled_time'].':00' : null,
                'created_by' => $user->id,
            ]);
            $project->forceFill(['last_activity_at' => now()])->save();

            return $version;
        });
    }
}
