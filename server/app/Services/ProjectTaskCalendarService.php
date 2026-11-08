<?php

namespace App\Services;

use App\Enums\ProjectTaskExecutionType as Type;
use App\Http\Resources\ProjectProgressNoteResource;
use App\Http\Resources\ProjectTaskOccurrenceResource;
use App\Http\Resources\ProjectTaskResource;
use App\Http\Resources\ProjectTaskScheduleVersionResource;
use App\Models\Project;
use App\Models\ProjectProgressNote;
use App\Models\ProjectTask;
use App\Models\ProjectTaskOccurrence;
use App\Models\ProjectTaskReview;
use App\Models\User;
use Carbon\CarbonImmutable;

class ProjectTaskCalendarService
{
    public function __construct(private readonly ProjectTaskRecurrenceService $recurrence, private readonly ProjectTaskReviewService $reviews) {}

    public function report(User $user, Project $project, string $from, string $to): array
    {
        $project->load('statusPeriods');
        $clock = now(config('app.timezone'));
        $date = 'COALESCE(due_date, scheduled_date)';
        $time = 'CASE WHEN due_date IS NOT NULL THEN due_time ELSE scheduled_time END';
        $tasks = ProjectTask::query()->where('project_id', $project->id)->visibleTo($user)
            ->with(['scheduleVersions', 'deletionPeriods', 'occurrences' => fn ($q) => $q
                ->where(fn ($q) => $q->whereBetween('scheduled_date', [$from, $to])->orWhere('occurrence_key', 'single'))])
            ->orderByRaw("CASE WHEN status = 'active' AND ({$date} < ? OR ({$date} = ? AND {$time} < ?)) THEN 0 ELSE 1 END",
                [$clock->toDateString(), $clock->toDateString(), $clock->format('H:i:s')])
            ->orderByRaw("CASE priority WHEN 'urgent' THEN 0 WHEN 'high' THEN 1 WHEN 'medium' THEN 2 ELSE 3 END")
            ->orderByRaw("CASE WHEN {$date} IS NULL THEN 1 ELSE 0 END")->orderByRaw($date)
            ->orderByRaw("COALESCE({$time}, '23:59:59')")->orderByDesc('id')->get();
        $recordedIds = $tasks->flatMap(fn ($task) => $task->occurrences->modelKeys());
        // Include deleted reservations for unchanged Multiple eligibility, but never
        // expose another author's deleted note. No queries inside the occurrence loop.
        $reservations = ProjectTaskReview::query()->whereIn('occurrence_id', $recordedIds)
            ->with('note:id,created_by,deleted_at')->get()->groupBy('occurrence_id');
        $visible = ProjectProgressNote::withTrashed()->visibleTo($user)
            ->whereIn('id', $reservations->flatten(1)->pluck('note_id'))
            ->where(fn ($q) => $q->whereNull('deleted_at')->orWhere('created_by', $user->id))
            ->get(['id', 'created_by', 'deleted_at'])->keyBy('id');
        $pages = [];
        $noteIds = collect();
        foreach ($reservations as $occurrenceId => $rows) {
            $task = $tasks->firstWhere('id', $rows->first()->task_id);
            $ordered = $rows->map(fn ($row) => $visible->get($row->note_id))->filter()
                ->sortBy(fn ($note) => [$task->review_mode === 'single' && $note->trashed() ? 1 : 0,
                    $note->created_by == $user->id ? 0 : 1, $note->id])->values();
            $pages[$occurrenceId] = $ordered;
            $noteIds = $noteIds->merge($ordered->take(20)->pluck('id'));
        }
        $notes = ProjectProgressNote::withTrashed()->whereIn('id', $noteIds)
            ->with(app(ProjectProgressNoteService::class)->relations($user))->get()->keyBy('id');
        $today = now($project->getAttributes()['timezone'] ?? config('app.timezone'))->toDateString();
        $result = [];
        foreach ($tasks as $task) {
            $task->setRelation('project', $project);
            if ($task->execution_type === Type::RECURRING) {
                $occurrences = $this->recurrence->occurrences($task, $from, $to, $task->occurrences);
            } elseif ($task->execution_type === Type::ONE_TIME) {
                $occurrences = collect([$task->occurrences->firstWhere('occurrence_key', 'single')
                    ?? new ProjectTaskOccurrence(['task_id' => $task->id, 'occurrence_key' => 'single',
                        'scheduled_date' => $task->scheduled_date, 'scheduled_time' => $task->scheduled_time])]);
            } else {
                $recorded = $task->occurrences->keyBy('occurrence_key');
                $occurrences = collect();
                for ($day = CarbonImmutable::parse($from); $day->toDateString() <= $to; $day = $day->addDay()) {
                    $date = $day->toDateString();
                    $occurrences->push($recorded->get($date) ?? new ProjectTaskOccurrence([
                        'task_id' => $task->id, 'occurrence_key' => $date, 'scheduled_date' => $date,
                        'scheduled_time' => $task->scheduled_time,
                    ]));
                }
            }
            // A recurring task with no schedule is still available in the undated section.
            if ($task->execution_type === Type::RECURRING && $task->scheduleVersions->isEmpty()) {
                for ($day = CarbonImmutable::parse($from); $day->toDateString() <= $to; $day = $day->addDay()) {
                    $date = $day->toDateString();
                    if (! $occurrences->contains('occurrence_key', $date)) {
                        $occurrences->push(new ProjectTaskOccurrence(['task_id' => $task->id,
                            'occurrence_key' => $date, 'scheduled_date' => $date, 'is_scheduled' => false]));
                    }
                }
            }
            $undated = $task->scheduled_date === null
                && ($task->execution_type !== Type::RECURRING || $task->scheduleVersions->isEmpty());
            $inRange = $task->scheduled_date !== null && $task->scheduled_date >= $from && $task->scheduled_date <= $to;
            if (! $undated && ! $inRange && ! $occurrences->contains(fn ($row) => $task->execution_type !== Type::ONE_TIME && ($row->exists || $row->getAttribute('is_scheduled')))) {
                continue;
            }
            $current = $task->scheduleVersions->first(fn ($version) => $version->effective_from <= $today
                && ($version->effective_until === null || $version->effective_until > $today));
            $latest = $task->scheduleVersions->sortByDesc('version')->first();
            $result[] = [
                'task' => (new ProjectTaskResource($task))->resolve(),
                'has_schedule' => $task->scheduleVersions->isNotEmpty(),
                'current_rule' => $current ? (new ProjectTaskScheduleVersionResource($current))->resolve() : null,
                'latest_rule' => $latest ? (new ProjectTaskScheduleVersionResource($latest))->resolve() : null,
                'occurrences' => $occurrences->map(function ($occurrence) use ($user, $task, $reservations, $pages, $notes) {
                    $rows = $reservations->get($occurrence->id, collect());
                    $page = $pages[$occurrence->id] ?? collect();

                    return [...(new ProjectTaskOccurrenceResource($occurrence))->resolve(), 'reviews' => [
                        'data' => $page->take(20)->map(fn ($note) => (new ProjectProgressNoteResource($notes->get($note->id)))->resolve())->values()->all(),
                        'meta' => ['current_page' => 1, 'last_page' => max(1, (int) ceil($page->count() / 20)), 'total' => $page->count()],
                        'review_context' => ['mode' => $task->review_mode,
                            'can_create' => $this->reviews->canCreate($user, $task, $occurrence->exists ? $occurrence : null, $rows),
                            'active_count' => $rows->filter(fn ($row) => $row->note !== null && ! $row->note->trashed())->count()],
                    ]];
                })->values()->all(),
            ];
        }

        return ['start_date' => $from, 'end_date' => $to, 'data' => $result];
    }
}
