<?php

namespace App\Http\Controllers\Api;

use App\Enums\ProjectTaskExecutionType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Project\SaveProjectTaskScheduleRequest;
use App\Http\Resources\ProjectTaskScheduleVersionResource;
use App\Models\Project;
use App\Models\ProjectTask;
use App\Services\ProjectTaskScheduleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class ProjectTaskScheduleController extends Controller
{
    public function __construct(private readonly ProjectTaskScheduleService $schedules) {}

    public function store(SaveProjectTaskScheduleRequest $request, Project $project, ProjectTask $task): JsonResponse
    {
        return (new ProjectTaskScheduleVersionResource($this->schedules->save($request->user(), $project, $task, $request->validated(), true)))
            ->response()->setStatusCode(201);
    }

    public function update(SaveProjectTaskScheduleRequest $request, Project $project, ProjectTask $task): JsonResponse
    {
        return (new ProjectTaskScheduleVersionResource($this->schedules->save($request->user(), $project, $task, $request->validated(), false)))
            ->response()->setStatusCode(200);
    }

    public function show(Request $request, Project $project, ProjectTask $task): JsonResponse
    {
        $this->authorizeRead($task);
        $date = now($project->getAttributes()['timezone'] ?? config('app.timezone'))->toDateString();
        $current = $task->scheduleVersions()->where('effective_from', '<=', $date)
            ->where(fn ($query) => $query->whereNull('effective_until')->orWhere('effective_until', '>', $date))->first();

        return response()->json(['data' => $current === null ? null : new ProjectTaskScheduleVersionResource($current)]);
    }

    public function index(Request $request, Project $project, ProjectTask $task): AnonymousResourceCollection
    {
        $this->authorizeRead($task);
        $input = $request->validate(['page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100']]);

        return ProjectTaskScheduleVersionResource::collection($task->scheduleVersions()->reorder()->orderByDesc('version')
            ->paginate($input['per_page'] ?? 20)->withQueryString());
    }

    private function authorizeRead(ProjectTask $task): void
    {
        Gate::authorize('view', $task);
        abort_unless($task->execution_type === ProjectTaskExecutionType::RECURRING, 422, 'Only recurring tasks have recurrence schedules.');
    }
}
