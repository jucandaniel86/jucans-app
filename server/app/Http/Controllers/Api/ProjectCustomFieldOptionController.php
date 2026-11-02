<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Project\ProjectConfigurationRequest;
use App\Http\Requests\Project\ReorderProjectConfigurationRequest;
use App\Http\Requests\Project\SaveProjectFieldOptionRequest;
use App\Http\Resources\ProjectCustomFieldOptionResource;
use App\Models\Project;
use App\Services\ProjectNoteConfigurationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class ProjectCustomFieldOptionController extends Controller
{
    public function __construct(private readonly ProjectNoteConfigurationService $configuration) {}

    public function index(Request $request, Project $project, int $field): AnonymousResourceCollection
    {
        Gate::authorize('view', $project);
        $field = $project->customFields()->findOrFail($field);
        $this->configuration->selectField($field);

        return ProjectCustomFieldOptionResource::collection($field->options()->get());
    }

    public function store(SaveProjectFieldOptionRequest $request, Project $project, int $field): JsonResponse
    {
        $option = $this->configuration->write($request->user(), $project,
            fn ($project) => $this->configuration->saveOption($project->customFields()->findOrFail($field), $request->validated()));

        return (new ProjectCustomFieldOptionResource($option))->response()->setStatusCode(201);
    }

    public function update(SaveProjectFieldOptionRequest $request, Project $project, int $field, int $option): ProjectCustomFieldOptionResource
    {
        return new ProjectCustomFieldOptionResource($this->configuration->write($request->user(), $project,
            function ($project) use ($request, $field, $option) {
                $field = $project->customFields()->findOrFail($field);

                return $this->configuration->saveOption($field, $request->validated(), $field->options()->findOrFail($option));
            }));
    }

    public function reorder(ReorderProjectConfigurationRequest $request, Project $project, int $field): AnonymousResourceCollection
    {
        $options = $this->configuration->write($request->user(), $project, function ($project) use ($request, $field) {
            $field = $project->customFields()->findOrFail($field);
            $this->configuration->reorderOptions($field, $request->validated('ids'));

            return $field->options()->get();
        });

        return ProjectCustomFieldOptionResource::collection($options);
    }

    public function archive(ProjectConfigurationRequest $request, Project $project, int $field, int $option): ProjectCustomFieldOptionResource
    {
        return $this->state($request, $project, $field, $option, true);
    }

    public function restore(ProjectConfigurationRequest $request, Project $project, int $field, int $option): ProjectCustomFieldOptionResource
    {
        return $this->state($request, $project, $field, $option, false);
    }

    private function state(ProjectConfigurationRequest $request, Project $project, int $field, int $option, bool $archive): ProjectCustomFieldOptionResource
    {
        return new ProjectCustomFieldOptionResource($this->configuration->write($request->user(), $project,
            function ($project) use ($field, $option, $archive) {
                $field = $project->customFields()->findOrFail($field);

                return $this->configuration->archiveOption($field, $field->options()->findOrFail($option), $archive);
            }));
    }
}
