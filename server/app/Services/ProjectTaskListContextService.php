<?php

namespace App\Services;

use App\Enums\ProjectTaskExecutionType;
use App\Http\Resources\ProjectTaskOccurrenceResource;
use App\Http\Resources\ProjectTaskResource;
use App\Http\Resources\ProjectTaskScheduleVersionResource;
use App\Models\Project;
use App\Models\ProjectTaskOccurrence;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

class ProjectTaskListContextService
{
    public function forTasks(User $user, Project $project, Collection $tasks, string $date): array
    {
        if ($tasks->isEmpty()) {
            return [];
        }
        $project->loadMissing('statusPeriods');
        $tasks->load(['scheduleVersions', 'deletionPeriods', 'occurrences' => fn ($query) => $query
            ->whereIn('occurrence_key', ['single', $date])]);
        $occurrences = collect();
        $eligibility = [];
        foreach ($tasks as $task) {
            $task->setRelation('project', $project);
            $key = $task->execution_type === ProjectTaskExecutionType::ONE_TIME ? 'single' : $date;
            $recorded = $task->occurrences->firstWhere('occurrence_key', $key);
            $occurrence = $task->execution_type === ProjectTaskExecutionType::RECURRING
                ? app(ProjectTaskRecurrenceService::class)->occurrences($task, $date, $date, $task->occurrences)->first()
                : $recorded;
            // Preserve List's existing single-occurrence endpoint semantics:
            // a recorded occurrence is readable/executable even outside the current rule.
            $eligibility[$task->id] = $task->execution_type !== ProjectTaskExecutionType::RECURRING
                || $recorded !== null || $occurrence !== null;
            $occurrence ??= new ProjectTaskOccurrence(['task_id' => $task->id,
                'occurrence_key' => $key, 'scheduled_date' => $key === 'single' ? $task->scheduled_date : $date,
                'scheduled_time' => $task->scheduled_time, 'due_date' => $task->due_date,
                'due_time' => $task->due_time, 'is_scheduled' => false]);
            $occurrences->push($occurrence);
        }
        $reviews = app(ProjectTaskReviewService::class)->forOccurrences($user, $tasks, $occurrences);
        $today = now($project->getAttributes()['timezone'] ?? config('app.timezone'))->toDateString();
        $result = [];
        foreach ($tasks as $index => $task) {
            $occurrence = $occurrences[$index];
            $current = $task->scheduleVersions->first(fn ($version) => $version->effective_from <= $today
                && ($version->effective_until === null || $version->effective_until > $today));
            $latest = $task->scheduleVersions->sortByDesc('version')->first();
            $result[$task->id] = ['task' => (new ProjectTaskResource($task))->resolve(),
                'occurrence_eligible' => $eligibility[$task->id],
                'has_schedule' => $task->scheduleVersions->isNotEmpty(),
                'current_rule' => $current ? (new ProjectTaskScheduleVersionResource($current))->resolve() : null,
                'latest_rule' => $latest ? (new ProjectTaskScheduleVersionResource($latest))->resolve() : null,
                'occurrences' => [[...(new ProjectTaskOccurrenceResource($occurrence))->resolve(),
                    'reviews' => $reviews[$task->id.':'.$occurrence->occurrence_key]]],
            ];
        }

        return $result;
    }
}
