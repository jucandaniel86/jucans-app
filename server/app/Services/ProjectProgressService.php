<?php

namespace App\Services;

use App\Enums\ProjectStatus;
use App\Enums\ProjectTaskExecutionType;
use App\Enums\ProjectTaskOccurrenceStatus;
use App\Models\Project;
use App\Models\ProjectTaskCompletionEvent;
use App\Models\ProjectTaskEvaluation;
use App\Models\ProjectTaskOccurrence;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

class ProjectProgressService
{
    public function __construct(private readonly ProjectTaskRecurrenceService $recurrence) {}

    public function report(User $user, Project $project, array $input, bool $summary = false, bool $details = false): array
    {
        Gate::forUser($user)->authorize('view', $project);
        $query = $project->tasks()->visibleTo($user);
        if (isset($input['task_id'])) {
            // Reject inaccessible filters, rather than leaking their existence through totals.
            (clone $query)->whereKey($input['task_id'])->firstOrFail();
            $query->whereKey($input['task_id']);
        }
        if (isset($input['execution_type'])) {
            $query->where('execution_type', $input['execution_type']);
        }
        $tasks = $query->with(['scheduleVersions', 'deletionPeriods'])->get();
        $project->load('statusPeriods');
        $tasks->each(fn ($task) => $task->setRelation('project', $project));
        $timezone = $project->getAttributes()['timezone'] ?? config('app.timezone');
        $end = CarbonImmutable::parse($input['date'] ?? CarbonImmutable::now($timezone)->toDateString(), $timezone)->startOfDay();
        $evaluations = ProjectTaskEvaluation::query()->active()->visibleTo($user)->whereIn('task_id', $tasks->modelKeys());
        $occurrences = ProjectTaskOccurrence::query()->whereIn('task_id', $tasks->modelKeys());
        $range = (string) ($input['range'] ?? '30');
        $page = $range === 'all' ? (int) ($input['page'] ?? 1) : 1;
        $earliest = null;
        if (! $summary && $range === 'all') {
            // Only visible history influences pagination bounds. MIN queries do not load history.
            $dates = array_filter([
                $tasks->min('scheduled_date'), $tasks->min(fn ($task) => $task->created_at->setTimezone($timezone)->toDateString()),
                (clone $evaluations)->min('evaluation_date'), (clone $occurrences)->min('scheduled_date'),
                ProjectTaskCompletionEvent::query()->whereIn('occurrence_id', (clone $occurrences)->select('id'))->min('performed_on'),
            ]);
            $earliest = min($end->toDateString(), $dates === [] ? $end->toDateString() : min($dates));
        }
        $lastPage = $earliest === null ? 1 : max(1, (int) ceil((max(0, CarbonImmutable::parse($earliest, $timezone)->diffInDays($end, false)) + 1) / 90));
        abort_if($page > $lastPage, 422, 'History page is outside the available range.');
        $to = $summary ? $end : $end->subDays(($page - 1) * 90);
        $from = $summary ? $to : $to->subDays(($range === 'all' ? 90 : (int) $range) - 1);
        if ($earliest !== null && $from->toDateString() < $earliest) {
            $from = CarbonImmutable::parse($earliest, $timezone)->startOfDay();
        }
        $fromDate = $from->toDateString();
        $toDate = $to->toDateString();
        $rows = (clone $occurrences)->where(fn ($q) => $q->whereBetween('scheduled_date', [$fromDate, $toDate])
            ->orWhere('occurrence_key', 'single'))->get();
        $byTask = $rows->groupBy('task_id');
        $scores = (clone $evaluations)->whereBetween('evaluation_date', [$fromDate, $toDate])
            ->orderBy('evaluation_date')->orderBy('id')->get();
        // A single has no dated key. Fetch only its latest action,
        // never the complete event log; dated projections already carry their date.
        $singleIds = $rows->where('occurrence_key', 'single')->modelKeys();
        $actionDates = ProjectTaskCompletionEvent::query()->whereIn('id', function ($q) use ($singleIds): void {
            $q->selectRaw('MAX(id)')->from('project_task_completion_events')->whereIn('occurrence_id', $singleIds)
                ->groupBy('occurrence_id');
        })->pluck('performed_on', 'occurrence_id');
        $days = [];
        for ($day = $from; $day <= $to; $day = $day->addDay()) {
            $days[$day->toDateString()] = ['date' => $day->toDateString(), 'scheduled_occurrences' => 0,
                'completed_scheduled_occurrences' => 0, 'skipped_scheduled_occurrences' => 0,
                'completion_percentage' => null, 'done_occurrences' => 0, 'evaluation_average' => null, 'evaluation_count' => 0];
        }
        $executionHistory = [];
        foreach ($tasks as $task) {
            $recorded = $byTask->get($task->id, collect());
            if ($task->execution_type === ProjectTaskExecutionType::RECURRING) {
                $projected = $this->recurrence->occurrences($task, $fromDate, $toDate, $recorded, true);
            } else {
                $projected = $recorded;
                $single = $recorded->firstWhere('occurrence_key', 'single');
                $scheduled = $single !== null ? $single->scheduled_date : $task->scheduled_date;
                if ($scheduled !== null && $scheduled >= $fromDate && $scheduled <= $toDate
                    && ($task->status === ProjectStatus::ACTIVE || $single !== null)) {
                    $key = $task->execution_type === ProjectTaskExecutionType::ONE_TIME ? 'single' : $scheduled;
                    if (! $projected->contains('occurrence_key', $key)) {
                        $projected = $projected->concat([new ProjectTaskOccurrence(['task_id' => $task->id,
                            'occurrence_key' => $key, 'scheduled_date' => $scheduled])]);
                    }
                }
                foreach ($projected as $row) {
                    $date = $row->scheduled_date;
                    $isScheduled = $task->execution_type === ProjectTaskExecutionType::ONE_TIME
                        ? $date !== null : $date === $task->scheduled_date;
                    $row->setAttribute('is_scheduled', $isScheduled && $date !== null
                        && $this->recurrence->eligibleDay($task, CarbonImmutable::parse($date, $timezone)->startOfDay()));
                }
            }
            foreach ($projected as $row) {
                $date = $row->scheduled_date;
                $done = $row->status === ProjectTaskOccurrenceStatus::DONE;
                if (isset($days[$date]) && $row->getAttribute('is_scheduled')) {
                    $days[$date]['scheduled_occurrences']++;
                    $days[$date]['completed_scheduled_occurrences'] += (int) $done;
                    $days[$date]['skipped_scheduled_occurrences'] += (int) ($row->status === ProjectTaskOccurrenceStatus::SKIPPED);
                }
                $doneDate = $row->occurrence_key === 'single' ? $actionDates->get($row->id) : $row->occurrence_key;
                if ($done && isset($days[$doneDate])) {
                    $days[$doneDate]['done_occurrences']++;
                }
                $historyDate = $row->occurrence_key === 'single' ? ($doneDate ?? $date) : $date;
                if ($details && isset($days[$historyDate])) {
                    $executionHistory[] = ['date' => $historyDate, 'scheduled_date' => $date, 'occurrence_key' => $row->occurrence_key,
                        'status' => $row->status->value, 'is_recorded' => $row->exists,
                        'is_scheduled' => (bool) $row->getAttribute('is_scheduled'), 'lock_version' => $row->lock_version];
                }
            }
        }
        foreach ($scores->groupBy('evaluation_date') as $date => $dailyScores) {
            $days[$date]['evaluation_average'] = (float) $dailyScores->avg('score');
            $days[$date]['evaluation_count'] = $dailyScores->count();
        }
        foreach ($days as &$day) {
            if ($day['scheduled_occurrences'] > 0) {
                $day['completion_percentage'] = round(100 * $day['completed_scheduled_occurrences'] / $day['scheduled_occurrences'], 2);
            }
        }
        unset($day);
        $result = ['project_id' => $project->id, 'progress_type' => $project->progress_type->value, 'timezone' => $timezone,
            'filters' => ['task_id' => isset($input['task_id']) ? (int) $input['task_id'] : null, 'execution_type' => $input['execution_type'] ?? null]];
        if ($summary) {
            $result['completion'] = $this->counts($tasks);
            $result['completion']['by_execution_type'] = [];
            foreach (ProjectTaskExecutionType::cases() as $type) {
                $result['completion']['by_execution_type'][$type->value] = $this->counts($tasks->where('execution_type', $type));
            }
            $result['daily'] = $days[$toDate];
        } else {
            $result['range'] = ['from' => $fromDate, 'to' => $toDate, 'selection' => $range,
                'page' => $page, 'last_page' => $lastPage, 'next_page' => $page < $lastPage ? $page + 1 : null];
            $result['series'] = array_values($days);
            if ($details) {
                usort($executionHistory, fn ($a, $b) => [$a['date'], $a['occurrence_key']] <=> [$b['date'], $b['occurrence_key']]);
                $result['executions'] = $executionHistory;
                $result['evaluations'] = $scores->map(fn ($score) => ['id' => $score->id, 'date' => $score->evaluation_date,
                    'score' => $score->score, 'lock_version' => $score->lock_version])->all();
            }
        }

        return $result;
    }

    private function counts(Collection $tasks): array
    {
        $workload = $tasks->where('status', '!=', ProjectStatus::ARCHIVED);
        $completed = $workload->where('status', ProjectStatus::COMPLETED)->count();

        return ['total_visible_tasks' => $tasks->count(), 'total_tasks' => $workload->count(),
            'completed_tasks' => $completed, 'remaining_tasks' => $workload->count() - $completed,
            'archived_tasks' => $tasks->count() - $workload->count()];
    }
}
