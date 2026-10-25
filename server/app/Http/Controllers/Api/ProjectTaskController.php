<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Project\ListProjectTasksRequest;
use App\Http\Requests\Project\StoreProjectTaskRequest;
use App\Http\Requests\Project\UpdateProjectTaskRequest;
use App\Http\Resources\ProjectTaskResource;
use App\Models\Project;
use App\Models\ProjectTask;
use App\Services\ProjectTaskService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ProjectTaskController extends Controller
{
    public function __construct(private readonly ProjectTaskService $tasks) {}

    public function index(ListProjectTasksRequest $request, Project $project): AnonymousResourceCollection
    {
        return $this->listing($request, $project);
    }

    public function deleted(ListProjectTasksRequest $request, Project $project): AnonymousResourceCollection
    {
        return $this->listing($request, $project, true);
    }

    public function store(StoreProjectTaskRequest $request, Project $project): JsonResponse
    {
        return (new ProjectTaskResource($this->tasks->create($request->user(), $project, $request->validated())))
            ->response()->setStatusCode(201);
    }

    public function show(Request $request, Project $project, ProjectTask $task): ProjectTaskResource
    {
        return new ProjectTaskResource($project->tasks()->visibleTo($request->user())->whereKey($task->id)->firstOrFail());
    }

    public function update(UpdateProjectTaskRequest $request, Project $project, ProjectTask $task): ProjectTaskResource
    {
        return new ProjectTaskResource($this->tasks->update($request->user(), $project, $task, $request->validated()));
    }

    public function destroy(Request $request, Project $project, ProjectTask $task): JsonResponse
    {
        $this->tasks->delete($request->user(), $project, $task);

        return response()->json(null, 204);
    }

    public function restore(Request $request, Project $project, ProjectTask $task): ProjectTaskResource
    {
        return new ProjectTaskResource($this->tasks->restore($request->user(), $project, $task));
    }

    private function listing(ListProjectTasksRequest $request, Project $project, bool $deleted = false): AnonymousResourceCollection
    {
        $validated = $request->validated();
        $query = ProjectTask::query()->where('project_id', $project->id)->visibleTo($request->user());
        if ($deleted) {
            $query->onlyTrashed();
            if (! $project->isOwnedBy($request->user())) {
                $query->where('created_by', $request->user()->id);
            }
        }
        if ($search = $validated['search'] ?? null) {
            $query->where(function (Builder $searchQuery) use ($search): void {
                $searchQuery->where('title', 'like', '%'.$search.'%')->orWhere('description', 'like', '%'.$search.'%');
            });
        }
        foreach (['execution_type', 'priority', 'status'] as $filter) {
            if (isset($validated[$filter])) {
                $query->where($filter, $validated[$filter]);
            }
        }
        $priority = "CASE priority WHEN 'urgent' THEN 0 WHEN 'high' THEN 1 WHEN 'medium' THEN 2 ELSE 3 END";
        $date = 'COALESCE(due_date, scheduled_date)';
        if (($validated['sort'] ?? 'priority') === 'overdue') {
            $clock = now(config('app.timezone'));
            $time = 'CASE WHEN due_date IS NOT NULL THEN due_time ELSE scheduled_time END';
            $query->orderByRaw("CASE WHEN status = 'active' AND ({$date} < ? OR ({$date} = ? AND {$time} < ?)) THEN 0 ELSE 1 END",
                [$clock->toDateString(), $clock->toDateString(), $clock->format('H:i:s')]);
        }
        if (in_array($validated['sort'] ?? 'priority', ['priority', 'overdue'], true)) {
            $query->orderByRaw($priority);
        }
        $query->orderByRaw("CASE WHEN {$date} IS NULL THEN 1 ELSE 0 END")->orderByRaw($date)
            ->orderByRaw("COALESCE(CASE WHEN due_date IS NOT NULL THEN due_time ELSE scheduled_time END, '23:59:59')");
        if (($validated['sort'] ?? 'priority') === 'date') {
            $query->orderByRaw($priority);
        }

        return ProjectTaskResource::collection($query->orderByDesc('id')
            ->paginate($validated['per_page'] ?? 20)->withQueryString());
    }
}
