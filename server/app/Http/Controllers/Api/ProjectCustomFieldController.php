<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Project\ProjectConfigurationRequest;
use App\Http\Requests\Project\SaveProjectCustomFieldRequest;
use App\Http\Resources\ProjectCustomFieldResource;
use App\Models\Project;
use App\Services\ProjectNoteConfigurationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class ProjectCustomFieldController extends Controller
{
    public function __construct(private readonly ProjectNoteConfigurationService $configuration) {}

    public function index(Request $request, Project $project): AnonymousResourceCollection
    {
        Gate::authorize('view', $project);

        return ProjectCustomFieldResource::collection($project->customFields()->orderBy('id')->get());
    }

    public function show(Request $request, Project $project, int $field): ProjectCustomFieldResource
    {
        Gate::authorize('view', $project);

        return new ProjectCustomFieldResource($project->customFields()->findOrFail($field));
    }

    public function store(SaveProjectCustomFieldRequest $request, Project $project): JsonResponse
    {
        $field = $this->configuration->write($request->user(), $project,
            fn ($project) => $this->configuration->saveField($project, $request->validated()));

        return (new ProjectCustomFieldResource($field))->response()->setStatusCode(201);
    }

    public function update(SaveProjectCustomFieldRequest $request, Project $project, int $field): ProjectCustomFieldResource
    {
        return new ProjectCustomFieldResource($this->configuration->write($request->user(), $project,
            fn ($project) => $this->configuration->saveField($project, $request->validated(), $project->customFields()->findOrFail($field))));
    }

    public function archive(ProjectConfigurationRequest $request, Project $project, int $field): ProjectCustomFieldResource
    {
        return $this->state($request, $project, $field, true);
    }

    public function restore(ProjectConfigurationRequest $request, Project $project, int $field): ProjectCustomFieldResource
    {
        return $this->state($request, $project, $field, false);
    }

    private function state(ProjectConfigurationRequest $request, Project $project, int $field, bool $archive): ProjectCustomFieldResource
    {
        return new ProjectCustomFieldResource($this->configuration->write($request->user(), $project,
            fn ($project) => $this->configuration->archiveField($project->customFields()->findOrFail($field), $archive)));
    }
}
