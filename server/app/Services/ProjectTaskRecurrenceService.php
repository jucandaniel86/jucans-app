<?php

namespace App\Services;

use App\Enums\ProjectRecurrenceType;
use App\Enums\ProjectStatus;
use App\Models\ProjectTask;
use App\Models\ProjectTaskOccurrence;
use App\Models\ProjectTaskScheduleVersion;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class ProjectTaskRecurrenceService
{
    /** When supplying batched records, preload scheduleVersions, deletionPeriods and project.statusPeriods. */
    public function occurrences(ProjectTask $task, string $from, string $to, ?Collection $recorded = null, bool $historical = false): Collection
    {
        if ($recorded === null) {
            $this->context($task);
        }
        $start = CarbonImmutable::parse($from, $this->timezone($task))->startOfDay();
        $end = CarbonImmutable::parse($to, $this->timezone($task))->startOfDay();
        if ($start->greaterThan($end) || $start->diffInDays($end) > 365) {
            throw ValidationException::withMessages(['to' => 'Use an ordered range of at most 366 calendar dates.']);
        }
        $recorded = ($recorded ?? $task->occurrences()->whereBetween('scheduled_date', [$from, $to])->get())->keyBy('occurrence_key');
        $result = collect();
        for ($day = $start; $day->lessThanOrEqualTo($end); $day = $day->addDay()) {
            $date = $day->toDateString();
            $occurrence = $recorded->get($date);
            // Statistics retain recorded inactive-task history without inventing virtual rows.
            $version = $this->eligibleVersion($task, $day, $historical && $occurrence !== null);
            if ($occurrence !== null || $version !== null) {
                $occurrence ??= new ProjectTaskOccurrence($this->snapshot($task, $version, $date));
                $occurrence->setAttribute('is_scheduled', $version !== null);
                $result->push($occurrence);
            }
        }

        return $result;
    }

    public function expected(ProjectTask $task, string $date): ?ProjectTaskOccurrence
    {
        $this->context($task);
        $day = CarbonImmutable::parse($date, $this->timezone($task))->startOfDay();
        $version = $this->eligibleVersion($task, $day);

        return $version === null ? null : new ProjectTaskOccurrence($this->snapshot($task, $version, $date));
    }

    private function context(ProjectTask $task): void
    {
        $task->load(['scheduleVersions', 'deletionPeriods', 'project.statusPeriods']);
    }

    private function timezone(ProjectTask $task): string
    {
        return $task->project->getAttributes()['timezone'] ?? config('app.timezone');
    }

    public function eligibleDay(ProjectTask $task, CarbonImmutable $day): bool
    {
        $boundary = $day->utc();
        $active = $task->project->statusPeriods->contains(fn ($period) => $period->status === ProjectStatus::ACTIVE
            && $period->starts_at->lessThanOrEqualTo($boundary)
            && ($period->ends_at === null || $period->ends_at->greaterThan($boundary)));

        return $active && ! $task->deletionPeriods->contains(fn ($period) => $period->deleted_at->lessThanOrEqualTo($boundary)
            && ($period->restored_at === null || $period->restored_at->greaterThan($boundary)));
    }

    private function eligibleVersion(ProjectTask $task, CarbonImmutable $day, bool $recordedHistory = false): ?ProjectTaskScheduleVersion
    {
        if ($task->trashed() || (! $recordedHistory && $task->status !== ProjectStatus::ACTIVE)
            || ! $this->eligibleDay($task, $day)) {
            return null;
        }
        $date = $day->toDateString();
        $version = $task->scheduleVersions->first(fn ($version) => $version->effective_from <= $date
            && ($version->effective_until === null || $version->effective_until > $date));
        if ($version === null || ($version->start_date !== null && $date < $version->start_date)
            || ($version->end_date !== null && $date > $version->end_date) || $date < $version->anchor_date) {
            return null;
        }
        $matches = match ($version->recurrence_type) {
            ProjectRecurrenceType::DAILY => true,
            ProjectRecurrenceType::EVERY_N_DAYS => CarbonImmutable::parse($version->anchor_date, $this->timezone($task))->diffInDays($day) % $version->interval_days === 0,
            ProjectRecurrenceType::WEEKDAYS => in_array($day->isoWeekday(), $version->weekdays, true),
            ProjectRecurrenceType::MONTHLY => $day->day === $version->month_day,
        };

        return $matches ? $version : null;
    }

    private function snapshot(ProjectTask $task, ProjectTaskScheduleVersion $version, string $date): array
    {
        return [
            'task_id' => $task->id,
            'occurrence_key' => $date,
            'schedule_version_id' => $version->id,
            'scheduled_date' => $date,
            'scheduled_time' => $version->scheduled_time,
            'due_date' => $task->due_date,
            'due_time' => $task->due_time,
        ];
    }
}
