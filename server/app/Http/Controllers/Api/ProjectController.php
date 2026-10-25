<?php

namespace App\Http\Controllers\Api;

use App\Enums\ProjectStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Project\ChangeProjectStatusRequest;
use App\Http\Requests\Project\ListProjectsRequest;
use App\Http\Requests\Project\StoreProjectRequest;
use App\Http\Requests\Project\UpdateProjectRequest;
use App\Http\Resources\ProjectResource;
use App\Models\Project;
use App\Services\ProjectService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class ProjectController extends Controller
{
    public function __construct(private readonly ProjectService $projects) {}

    public function index(ListProjectsRequest $request): AnonymousResourceCollection
    {
        $validated = $request->validated();
        $status = $validated['status'] ?? 'active';
        $query = Project::query()->accessibleTo($request->user())
            ->select('projects.*')
            ->join('project_categories', 'projects.category_id', '=', 'project_categories.id')
            ->with(['category', 'owner:id,username,avatar']);

        if ($status === 'inactive') {
            $query->whereIn('projects.status', ['completed', 'archived']);
        } elseif ($status !== 'all') {
            $query->where('projects.status', $status);
        }
        if (isset($validated['category_id'])) {
            $query->where('projects.category_id', $validated['category_id']);
        }

        return ProjectResource::collection($query
            ->orderBy('project_categories.sort_order')->orderBy('project_categories.id')
            ->orderByDesc('projects.last_activity_at')->orderByDesc('projects.id')
            ->paginate($validated['per_page'] ?? 20)->withQueryString());
    }

    public function store(StoreProjectRequest $request): JsonResponse
    {
        return (new ProjectResource($this->projects->create($request->user(), $request->validated())))
            ->response()->setStatusCode(201);
    }

    public function show(Request $request, Project $project): ProjectResource
    {
        $project = Project::query()->accessibleTo($request->user())->whereKey($project->id)->firstOrFail();
        Gate::authorize('view', $project);

        return new ProjectResource($project->load(['category', 'owner:id,username,avatar', 'statusPeriods']));
    }

    public function update(UpdateProjectRequest $request, Project $project): ProjectResource
    {
        return new ProjectResource($this->projects->update($request->user(), $project, $request->validated()));
    }

    public function status(ChangeProjectStatusRequest $request, Project $project): ProjectResource
    {
        return new ProjectResource($this->projects->changeStatus(
            $request->user(), $project, ProjectStatus::from($request->validated('status'))
        ));
    }
}
