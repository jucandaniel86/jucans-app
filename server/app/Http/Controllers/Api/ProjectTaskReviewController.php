<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProjectProgressNoteResource;
use App\Models\Project;
use App\Models\ProjectTask;
use App\Models\ProjectTaskReview;
use App\Services\ProjectProgressNoteService;
use App\Services\ProjectTaskExecutionService;
use App\Services\ProjectTaskReviewService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class ProjectTaskReviewController extends Controller
{
    public function index(Request $request, Project $project, ProjectTask $task): AnonymousResourceCollection
    {
        Gate::authorize('view', $task);
        $input = $request->validate(['occurrence_key' => ['required', 'string', 'max:10'], 'page' => ['sometimes', 'integer', 'min:1']]);
        app(ProjectTaskExecutionService::class)->validateKey($task, $input['occurrence_key']);
        $task->setRelation('project', $project);
        $occurrence = $task->occurrences()->where('occurrence_key', $input['occurrence_key'])->first();
        $ids = ProjectTaskReview::query()->where('task_id', $task->id)->where('occurrence_id', $occurrence?->id)->select('note_id');
        $notes = $project->notes()->withTrashed()->visibleTo($request->user())->whereIn('id', $ids)
            ->where(fn ($q) => $q->whereNull('deleted_at')->orWhere('created_by', $request->user()->id))
            ->with(app(ProjectProgressNoteService::class)->relations($request->user()))
            ->when($task->review_mode === 'single', fn ($query) => $query->orderByRaw('CASE WHEN deleted_at IS NULL THEN 0 ELSE 1 END'))
            ->orderByRaw('CASE WHEN created_by = ? THEN 0 ELSE 1 END', [$request->user()->id])->orderBy('id')
            ->paginate(20)->withQueryString();

        return ProjectProgressNoteResource::collection($notes)->additional(['review_context' => [
            'mode' => $task->review_mode,
            'can_create' => app(ProjectTaskReviewService::class)->canCreate($request->user(), $task, $occurrence),
        ]]);
    }
}
