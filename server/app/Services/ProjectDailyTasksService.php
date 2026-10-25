<?php

namespace App\Services;

use App\Enums\ProjectStatus;
use App\Enums\ProjectTaskExecutionType as Type;
use App\Enums\ProjectTaskOccurrenceStatus as State;
use App\Http\Resources\ProjectResource;
use App\Http\Resources\ProjectTaskOccurrenceResource;
use App\Http\Resources\ProjectTaskResource;
use App\Models\ProjectTask;
use App\Models\ProjectTaskCompletionEvent;
use App\Models\ProjectTaskOccurrence;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

class ProjectDailyTasksService
{
    public function __construct(private readonly ProjectTaskRecurrenceService $recurrence) {}

    public function report(User $user, array $input): array
    {
        // Projects currently have no timezone field; use the same fallback as progress/executions.
        $timezone = config('app.timezone');
        $now = CarbonImmutable::now($timezone);
        $day = $now->startOfDay();
        $date = $day->toDateString();
        $rows = collect();
        $projects = [];
        $summary = ['scheduled' => 0, 'completed' => 0];
        $query = ProjectTask::query()->visibleTo($user)->where('show_in_daily_widget', true)
            ->whereHas('project', fn (Builder $q) => $q->where('status', ProjectStatus::ACTIVE))
            ->where(function (Builder $q) use ($date): void {
                $q->where(fn (Builder $q) => $q->where('execution_type', '!=', Type::ONE_TIME)->where('status', ProjectStatus::ACTIVE))
                    ->orWhere(function (Builder $q) use ($date): void {
                        $q->where('execution_type', Type::ONE_TIME)->where(function (Builder $q) use ($date): void {
                            $q->where(fn (Builder $q) => $q->where('status', ProjectStatus::ACTIVE)
                                ->where(fn (Builder $q) => $q->where('scheduled_date', '<=', $date)->orWhere('due_date', '<=', $date)))
                                ->orWhere(fn (Builder $q) => $q->where('status', ProjectStatus::COMPLETED)
                                    ->where(fn (Builder $q) => $q->where('scheduled_date', $date)
                                        ->orWhereHas('occurrences.events', fn (Builder $q) => $q->where('performed_on', $date))));
                        });
                    });
            });
        $query->with(['project.category', 'project.owner:id,username,avatar', 'project.statusPeriods',
            'scheduleVersions', 'deletionPeriods',
            'occurrences' => fn ($q) => $q->where(fn ($q) => $q->where('occurrence_key', 'single')->orWhere('occurrence_key', $date)),
        ])->chunkById(200, function ($tasks) use (&$rows, &$projects, &$summary, $date, $day, $now): void {
            $singleIds = $tasks->flatMap(fn ($task) => $task->occurrences->where('occurrence_key', 'single')->modelKeys());
            $actionDates = ProjectTaskCompletionEvent::query()->whereIn('id', function ($q) use ($singleIds): void {
                $q->selectRaw('MAX(id)')->from('project_task_completion_events')->whereIn('occurrence_id', $singleIds)->groupBy('occurrence_id');
            })->pluck('performed_on', 'occurrence_id');
            foreach ($tasks as $task) {
                $single = $task->execution_type === Type::ONE_TIME;
                $key = $single ? 'single' : $date;
                $occurrence = $task->occurrences->firstWhere('occurrence_key', $key);
                if ($task->execution_type === Type::RECURRING) {
                    $occurrence = $this->recurrence->occurrences($task, $date, $date, $task->occurrences)->first();
                    if (! $occurrence?->getAttribute('is_scheduled')) {
                        continue;
                    }
                    $scheduled = true;
                } else {
                    $scheduled = $task->scheduled_date === $date && $this->recurrence->eligibleDay($task, $day);
                    $occurrence ??= new ProjectTaskOccurrence([
                        'task_id' => $task->id, 'occurrence_key' => $key,
                        'scheduled_date' => $single ? $task->scheduled_date : $date,
                        'scheduled_time' => $task->scheduled_time,
                    ]);
                    $occurrence->setAttribute('is_scheduled', $scheduled);
                }
                $done = $occurrence->status === State::DONE;
                if ($scheduled) {
                    $summary['scheduled']++;
                    $summary['completed'] += (int) $done;
                }
                if ($done) {
                    if ($single && $task->scheduled_date !== $date && $actionDates->get($occurrence->id) !== $date) {
                        continue;
                    }
                    $section = 'completed';
                } elseif ($task->execution_type === Type::PERSISTENT) {
                    $section = 'persistent';
                } elseif ($single) {
                    // Group using the editable definition, never an old occurrence snapshot after rescheduling.
                    $deadline = $task->due_date ?? $task->scheduled_date;
                    $time = $task->due_date !== null ? $task->due_time : $task->scheduled_time;
                    $overdue = $deadline !== null && ($deadline < $date
                        || ($deadline === $date && $time !== null && substr($time, 0, 5) < $now->format('H:i')));
                    if (! $overdue && $task->scheduled_date !== $date && $task->due_date !== $date) {
                        continue;
                    }
                    $section = $overdue ? 'overdue' : 'today';
                } else {
                    $section = 'today';
                }
                $rows->push(['section' => $section, 'task' => (new ProjectTaskResource($task))->resolve(),
                    'occurrence' => (new ProjectTaskOccurrenceResource($occurrence))->resolve()]);
                if (! isset($projects[$task->project_id])) {
                    $project = clone $task->project;
                    $project->unsetRelation('statusPeriods');
                    $projects[$task->project_id] = (new ProjectResource($project))->resolve();
                }
            }
        });
        $counts = collect(['overdue', 'today', 'persistent', 'completed'])->mapWithKeys(fn ($section) => [$section => $rows->where('section', $section)->count()])->all();
        $completed = ($input['section'] ?? 'active') === 'completed';
        $filtered = $rows->filter(fn ($row) => ($row['section'] === 'completed') === $completed);
        $priority = ['urgent' => 0, 'high' => 1, 'medium' => 2, 'low' => 3];
        $order = ['overdue' => 0, 'today' => 1, 'persistent' => 2, 'completed' => 3];
        $filtered = $filtered->sortBy(fn ($row) => [$order[$row['section']], $priority[$row['task']['priority']],
            $row['task']['due_date'] ?? $row['task']['scheduled_date'] ?? '9999-12-31', $row['task']['id']])->values();
        $page = (int) ($input['page'] ?? 1);
        $perPage = (int) ($input['per_page'] ?? 20);
        $visible = $filtered->forPage($page, $perPage)->values();

        return ['date' => $date, 'timezone' => $timezone, 'summary' => $summary, 'counts' => $counts,
            'rows' => $visible->all(),
            'projects' => array_values(array_intersect_key($projects, array_flip($visible->pluck('task.project_id')->all()))),
            'pagination' => ['page' => $page, 'last_page' => max(1, (int) ceil($filtered->count() / $perPage)), 'total' => $filtered->count()]];
    }
}
