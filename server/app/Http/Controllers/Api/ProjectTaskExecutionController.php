<?php

namespace App\Http\Controllers\Api;

use App\Enums\ProjectTaskCompletionAction;
use App\Enums\ProjectTaskExecutionType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Project\ExecuteProjectTaskRequest;
use App\Http\Requests\Project\ListProjectTaskExecutionsRequest;
use App\Http\Resources\ProjectTaskCompletionEventResource;
use App\Http\Resources\ProjectTaskOccurrenceResource;
use App\Http\Resources\ProjectTaskResource;
use App\Models\Project;
use App\Models\ProjectTask;
use App\Models\ProjectTaskCompletionEvent;
use App\Services\ProjectTaskExecutionService;
use App\Services\ProjectTaskRecurrenceService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;

class ProjectTaskExecutionController extends Controller
{
    public function __construct(private readonly ProjectTaskExecutionService $executions, private readonly ProjectTaskRecurrenceService $recurrence) {}

    public function index(ListProjectTaskExecutionsRequest $request, Project $project, ProjectTask $task): AnonymousResourceCollection
    {
        $input = $request->validated();
        if ($task->execution_type === ProjectTaskExecutionType::RECURRING) {
            $rows = $this->recurrence->occurrences($task, $input['from'], $input['to']);
            $page = (int) ($input['page'] ?? 1);
            $perPage = (int) ($input['per_page'] ?? 20);

            return ProjectTaskOccurrenceResource::collection((new LengthAwarePaginator(
                $rows->forPage($page, $perPage)->values(), $rows->count(), $perPage, $page, ['path' => $request->url()]
            ))->withQueryString());
        }

        return ProjectTaskOccurrenceResource::collection($task->occurrences()
            ->where(fn (Builder $query) => $query->whereBetween('scheduled_date', [$input['from'], $input['to']])->orWhereNull('scheduled_date'))
            ->orderBy('scheduled_date')->orderBy('id')->paginate($input['per_page'] ?? 20)->withQueryString());
    }

    public function show(Request $request, Project $project, ProjectTask $task, string $occurrenceKey): ProjectTaskOccurrenceResource
    {
        Gate::authorize('view', $task);
        $this->executions->validateKey($task, $occurrenceKey);

        $occurrence = $task->occurrences()->where('occurrence_key', $occurrenceKey)->first();
        if ($occurrence === null && $task->execution_type === ProjectTaskExecutionType::RECURRING) {
            $occurrence = $this->recurrence->expected($task, $occurrenceKey);
        }
        abort_if($occurrence === null, 404);

        return new ProjectTaskOccurrenceResource($occurrence);
    }

    public function done(ExecuteProjectTaskRequest $request, Project $project, ProjectTask $task, string $occurrenceKey): JsonResponse
    {
        return (new ProjectTaskOccurrenceResource($this->executions->execute($request->user(), $project, $task, $occurrenceKey, ProjectTaskCompletionAction::DONE, $request->validated())))
            ->response()->setStatusCode(200);
    }

    public function undo(ExecuteProjectTaskRequest $request, Project $project, ProjectTask $task, string $occurrenceKey): JsonResponse
    {
        return (new ProjectTaskOccurrenceResource($this->executions->execute($request->user(), $project, $task, $occurrenceKey, ProjectTaskCompletionAction::UNDO, $request->validated())))
            ->response()->setStatusCode(200);
    }

    public function skip(ExecuteProjectTaskRequest $request, Project $project, ProjectTask $task, string $occurrenceKey): JsonResponse
    {
        return (new ProjectTaskOccurrenceResource($this->executions->execute($request->user(), $project, $task, $occurrenceKey, ProjectTaskCompletionAction::SKIP, $request->validated())))
            ->response()->setStatusCode(200);
    }

    public function history(ListProjectTaskExecutionsRequest $request, Project $project, ProjectTask $task): AnonymousResourceCollection
    {
        $input = $request->validated();

        return ProjectTaskCompletionEventResource::collection(ProjectTaskCompletionEvent::query()
            ->whereHas('occurrence', fn (Builder $query) => $query->where('task_id', $task->id))
            ->whereBetween('performed_on', [$input['from'], $input['to']])
            ->orderBy('performed_on')->orderBy('id')->paginate($input['per_page'] ?? 20)->withQueryString());
    }

    public function complete(Request $request, Project $project, ProjectTask $task): ProjectTaskResource
    {
        return new ProjectTaskResource($this->executions->completePersistent($request->user(), $project, $task));
    }
}
