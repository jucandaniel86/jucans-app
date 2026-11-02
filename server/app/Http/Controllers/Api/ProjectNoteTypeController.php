<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Project\ProjectConfigurationRequest;
use App\Http\Requests\Project\SaveProjectNoteTypeRequest;
use App\Http\Resources\ProjectNoteTypeResource;
use App\Models\Project;
use App\Services\ProjectNoteConfigurationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class ProjectNoteTypeController extends Controller
{
    public function __construct(private readonly ProjectNoteConfigurationService $configuration) {}

    public function index(Request $request, Project $project): AnonymousResourceCollection
    {
        Gate::authorize('view', $project);

        return ProjectNoteTypeResource::collection($project->noteTypes()->orderBy('id')->get());
    }

    public function show(Request $request, Project $project, int $type): ProjectNoteTypeResource
    {
        Gate::authorize('view', $project);

        return new ProjectNoteTypeResource($project->noteTypes()->findOrFail($type));
    }

    public function store(SaveProjectNoteTypeRequest $request, Project $project): JsonResponse
    {
        $type = $this->configuration->write($request->user(), $project,
            fn ($project) => $this->configuration->saveType($project, $request->validated()));

        return (new ProjectNoteTypeResource($type))->response()->setStatusCode(201);
    }

    public function update(SaveProjectNoteTypeRequest $request, Project $project, int $type): ProjectNoteTypeResource
    {
        return new ProjectNoteTypeResource($this->configuration->write($request->user(), $project,
            fn ($project) => $this->configuration->saveType($project, $request->validated(), $project->noteTypes()->findOrFail($type))));
    }

    public function setDefault(ProjectConfigurationRequest $request, Project $project, int $type): ProjectNoteTypeResource
    {
        return new ProjectNoteTypeResource($this->configuration->write($request->user(), $project,
            fn ($project) => $this->configuration->setDefault($project, $project->noteTypes()->findOrFail($type))));
    }

    public function archive(ProjectConfigurationRequest $request, Project $project, int $type): ProjectNoteTypeResource
    {
        return $this->state($request, $project, $type, true);
    }

    public function restore(ProjectConfigurationRequest $request, Project $project, int $type): ProjectNoteTypeResource
    {
        return $this->state($request, $project, $type, false);
    }

    private function state(ProjectConfigurationRequest $request, Project $project, int $type, bool $archive): ProjectNoteTypeResource
    {
        return new ProjectNoteTypeResource($this->configuration->write($request->user(), $project,
            fn ($project) => $this->configuration->archiveType($project, $project->noteTypes()->findOrFail($type), $archive)));
    }
}
