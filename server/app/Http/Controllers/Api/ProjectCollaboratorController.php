<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Project\SetProjectTaskGrantsRequest;
use App\Http\Requests\Project\StoreProjectCollaboratorRequest;
use App\Http\Requests\Project\UpdateProjectCollaboratorRequest;
use App\Http\Resources\ProjectCollaboratorResource;
use App\Models\Project;
use App\Models\ProjectCollaborator;
use App\Services\ProjectSharingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class ProjectCollaboratorController extends Controller
{
    public function __construct(private readonly ProjectSharingService $sharing) {}

    public function index(Project $project): AnonymousResourceCollection
    {
        Gate::authorize('viewCollaborators', $project);

        return ProjectCollaboratorResource::collection($project->collaborators()
            ->with(['user:id,username,avatar', 'taskGrants' => fn ($query) => $query->orderBy('task_id')])
            ->orderBy('id')->get());
    }

    public function store(StoreProjectCollaboratorRequest $request, Project $project): JsonResponse
    {
        return (new ProjectCollaboratorResource($this->sharing->add($request->user(), $project, $request->validated())))
            ->response()->setStatusCode(201);
    }

    public function update(UpdateProjectCollaboratorRequest $request, Project $project, ProjectCollaborator $collaborator): ProjectCollaboratorResource
    {
        return new ProjectCollaboratorResource($this->sharing->update($request->user(), $project, $collaborator, $request->validated()));
    }

    public function grants(SetProjectTaskGrantsRequest $request, Project $project, ProjectCollaborator $collaborator): ProjectCollaboratorResource
    {
        return new ProjectCollaboratorResource($this->sharing->setGrants($request->user(), $project, $collaborator, $request->validated('task_ids')));
    }

    public function destroy(Request $request, Project $project, ProjectCollaborator $collaborator): JsonResponse
    {
        $this->sharing->remove($request->user(), $project, $collaborator);

        return response()->json(null, 204);
    }
}
