<?php

namespace App\Services;

use App\Enums\ProjectStatus;
use App\Enums\ProjectTaskOccurrenceStatus;
use App\Http\Resources\ProjectProgressNoteResource;
use App\Models\Project;
use App\Models\ProjectProgressNote;
use App\Models\ProjectTask;
use App\Models\ProjectTaskOccurrence;
use App\Models\ProjectTaskReview;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

class ProjectTaskReviewService
{
    public function canCreate(User $user, ProjectTask $task, ?ProjectTaskOccurrence $occurrence, ?Collection $loadedReviews = null): bool
    {
        if ($occurrence === null || $occurrence->status === ProjectTaskOccurrenceStatus::PENDING
            || $task->project->status !== ProjectStatus::ACTIVE
            || $task->trashed() || $task->status === ProjectStatus::ARCHIVED) {
            return false;
        }
        if ($loadedReviews !== null) {
            $reserved = $task->review_mode === 'single'
                ? $loadedReviews->filter(fn ($review) => $review->note !== null && ! $review->note->trashed())
                : $loadedReviews;

            return ! $reserved->contains('user_id', $user->id)
                && ($task->review_mode !== 'single' || $reserved->isEmpty());
        }
        $reviews = ProjectTaskReview::query()->where('occurrence_id', $occurrence->id);
        if ($task->review_mode === 'single') {
            $reviews->whereHas('note', fn ($query) => $query->whereNull('project_progress_notes.deleted_at'));
        }
        if ((clone $reviews)->where('user_id', $user->id)->exists()
            || ($task->review_mode === 'single' && (clone $reviews)->exists())) {
            return false;
        }

        return true;
    }

    /** Batch the same review resources/visibility/order as PERF-01 for daily rows.
     *  All reservations participate in eligibility; only 20 visible notes are expanded.
     */
    public function forOccurrences(User $user, Collection $tasks, Collection $occurrences): array
    {
        $tasks = $tasks->keyBy('id');
        $reservations = ProjectTaskReview::query()->whereIn('occurrence_id', $occurrences->pluck('id')->filter())
            ->with('note:id,created_by,deleted_at')->get()->groupBy('occurrence_id');
        $visible = ProjectProgressNote::withTrashed()->visibleTo($user)
            ->whereIn('id', $reservations->flatten(1)->pluck('note_id'))
            ->where(fn ($q) => $q->whereNull('deleted_at')->orWhere('created_by', $user->id))
            ->get(['id', 'created_by', 'deleted_at'])->keyBy('id');
        $pages = [];
        $noteIds = collect();
        foreach ($reservations as $id => $rows) {
            $task = $tasks->get($rows->first()->task_id);
            $page = $rows->map(fn ($row) => $visible->get($row->note_id))->filter()
                ->sortBy(fn ($note) => [$task->review_mode === 'single' && $note->trashed() ? 1 : 0,
                    $note->created_by == $user->id ? 0 : 1, $note->id])->values();
            $pages[$id] = $page;
            $noteIds = $noteIds->merge($page->take(20)->pluck('id'));
        }
        $notes = ProjectProgressNote::withTrashed()->whereIn('id', $noteIds)
            ->with(app(ProjectProgressNoteService::class)->relations($user))->get()->keyBy('id');
        $result = [];
        foreach ($occurrences as $occurrence) {
            $task = $tasks->get($occurrence->task_id);
            $rows = $reservations->get($occurrence->id, collect());
            $page = $pages[$occurrence->id] ?? collect();
            $result[$task->id.':'.$occurrence->occurrence_key] = [
                'data' => $page->take(20)->map(fn ($note) => (new ProjectProgressNoteResource($notes->get($note->id)))->resolve())->values()->all(),
                'meta' => ['current_page' => 1, 'last_page' => max(1, (int) ceil($page->count() / 20)), 'total' => $page->count()],
                'review_context' => ['mode' => $task->review_mode,
                    'can_create' => $this->canCreate($user, $task, $occurrence->exists ? $occurrence : null, $rows),
                    'active_count' => $rows->filter(fn ($row) => $row->note !== null && ! $row->note->trashed())->count()],
            ];
        }

        return $result;
    }

    // Caller already holds the parent project lock inside the note transaction.
    public function attach(User $user, Project $project, ProjectProgressNote $note, array $input): void
    {
        $task = $project->tasks()->whereKey($input['review_task_id'])->lockForUpdate()->firstOrFail()->setRelation('project', $project);
        Gate::forUser($user)->authorize('view', $task);
        app(ProjectTaskExecutionService::class)->validateKey($task, $input['review_occurrence_key']);
        abort_unless(count($input['task_ids'] ?? []) === 1 && (int) $input['task_ids'][0] === (int) $task->id, 422, 'A review must reference exactly its task.');
        $occurrence = $task->occurrences()->where('occurrence_key', $input['review_occurrence_key'])->first();
        abort_if($occurrence === null || $occurrence->status === ProjectTaskOccurrenceStatus::PENDING,
            422, 'Cannot create a review while the task occurrence is To Do. Mark it Done or Cannot Be Done first.');
        // Also release pre-deployment Single tombstones under the existing project lock.
        if ($task->review_mode === 'single') {
            ProjectTaskReview::query()->where('occurrence_id', $occurrence->id)
                ->whereHas('note', fn ($query) => $query->whereNotNull('project_progress_notes.deleted_at'))
                ->update(['active_slot' => null]);
        }
        abort_unless($this->canCreate($user, $task, $occurrence), 409, 'Review unavailable: the slot is occupied or the task is inactive.');
        ProjectTaskReview::create([
            'project_id' => $project->id, 'task_id' => $task->id, 'occurrence_id' => $occurrence->id,
            'note_id' => $note->id, 'user_id' => $user->id,
            'active_slot' => '1',
            'slot' => $task->review_mode === 'single' ? 0 : $user->id,
        ]);
    }

    // Both hooks run inside the note service's existing project-lock transaction.
    public function releaseDeletedSingle(ProjectProgressNote $note): void
    {
        $review = $note->review;
        if (! $note->trashed() || $review === null || ! $this->isSingle($note, $review)) {
            return;
        }
        $review->forceFill(['active_slot' => null])->save();
    }

    public function reserveRestoredSingle(ProjectProgressNote $note): void
    {
        $review = $note->review;
        if ($review === null || ! $this->isSingle($note, $review)) {
            return;
        }
        abort_if(ProjectTaskReview::query()->where('occurrence_id', $review->occurrence_id)
            ->where('id', '!=', $review->id)
            ->whereHas('note', fn ($query) => $query->whereNull('project_progress_notes.deleted_at'))->exists(),
            409, 'An active Single Review already exists for this occurrence. The deleted review remains preserved.');
        // Another deleted note may have been created/deleted by the old deployment.
        ProjectTaskReview::query()->where('occurrence_id', $review->occurrence_id)
            ->where('id', '!=', $review->id)
            ->whereHas('note', fn ($query) => $query->whereNotNull('project_progress_notes.deleted_at'))
            ->update(['active_slot' => null]);
        $review->forceFill(['active_slot' => '1'])->save();
    }

    private function isSingle(ProjectProgressNote $note, ProjectTaskReview $review): bool
    {
        return ProjectTask::withTrashed()->where('project_id', $note->project_id)
            ->whereKey($review->task_id)->value('review_mode') === 'single';
    }

    // Same project/task lock as review creation; preserves even soft-deleted reviews.
    public function changeMode(ProjectTask $task): void
    {
        if (! $task->isDirty('review_mode')) {
            return;
        }
        $reviews = ProjectTaskReview::query()->where('task_id', $task->id);
        if ($task->review_mode === 'single') {
            abort_if((clone $reviews)->select('occurrence_id')->groupBy('occurrence_id')->havingRaw('COUNT(*) > 1')->exists(),
                409, 'Cannot switch to Single: an occurrence already has multiple reviews.');
            $reviews->update(['slot' => 0, 'active_slot' => '1']);
            (clone $reviews)->whereHas('note', fn ($query) => $query->whereNotNull('project_progress_notes.deleted_at'))
                ->update(['active_slot' => null]);
        } else {
            // Multiple still reserves one slot per author, including deleted notes.
            // Single history can now contain repeated reviews by that author.
            abort_if((clone $reviews)->select(['occurrence_id', 'user_id'])->groupBy('occurrence_id', 'user_id')
                ->havingRaw('COUNT(*) > 1')->exists(), 409,
                'Cannot switch to Multiple: historical reviews would occupy the same author slot. No history was removed.');
            foreach ($reviews->get() as $review) {
                $review->update(['slot' => $review->user_id, 'active_slot' => '1']);
            }
        }
    }
}
