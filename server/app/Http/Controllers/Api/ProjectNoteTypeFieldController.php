<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Project\ProjectConfigurationRequest;
use App\Http\Requests\Project\ReorderProjectConfigurationRequest;
use App\Http\Requests\Project\SaveProjectNoteTypeFieldRequest;
use App\Http\Resources\ProjectNoteTypeFieldResource;
use App\Models\Project;
use App\Services\ProjectNoteConfigurationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class ProjectNoteTypeFieldController extends Controller
{
    public function __construct(private readonly ProjectNoteConfigurationService $configuration) {}

    public function index(Request $request, Project $project, int $type): AnonymousResourceCollection
    {
        Gate::authorize('view', $project);
        $request->validate(['history' => ['sometimes', 'boolean']]);
        $type = $project->noteTypes()->findOrFail($type);
        $query = $request->boolean('history') ? $type->fieldHistory()->orderBy('id') : $type->fields();

        return ProjectNoteTypeFieldResource::collection($query->with('field')->get());
    }

    public function store(SaveProjectNoteTypeFieldRequest $request, Project $project, int $type): JsonResponse
    {
        $association = $this->configuration->write($request->user(), $project, function ($project) use ($request, $type) {
            $input = $request->validated();

            return $this->configuration->attach($project, $project->noteTypes()->findOrFail($type),
                $project->customFields()->findOrFail($input['field_id']), $input);
        });

        return (new ProjectNoteTypeFieldResource($association->load('field')))->response()->setStatusCode(201);
    }

    public function create(SaveProjectNoteTypeFieldRequest $request, Project $project, int $type): JsonResponse
    {
        $association = $this->configuration->write($request->user(), $project, function ($project) use ($request, $type) {
            $type = $project->noteTypes()->findOrFail($type);
            $input = $request->validated();
            $field = $this->configuration->saveField($project, $input['field']);

            return $this->configuration->attach($project, $type, $field, $input);
        });

        return (new ProjectNoteTypeFieldResource($association->load('field')))->response()->setStatusCode(201);
    }

    public function update(SaveProjectNoteTypeFieldRequest $request, Project $project, int $type, int $field): ProjectNoteTypeFieldResource
    {
        $association = $this->configuration->write($request->user(), $project,
            fn ($project) => $this->configuration->changeAssociation($project->noteTypes()->findOrFail($type),
                $project->customFields()->findOrFail($field), $request->safe()->only(['required', 'sort_order'])));

        return new ProjectNoteTypeFieldResource($association->fresh()->load('field'));
    }

    public function destroy(ProjectConfigurationRequest $request, Project $project, int $type, int $field): JsonResponse
    {
        $this->configuration->write($request->user(), $project,
            fn ($project) => $this->configuration->changeAssociation($project->noteTypes()->findOrFail($type),
                $project->customFields()->findOrFail($field), [], true));

        return response()->json(null, 204);
    }

    public function reorder(ReorderProjectConfigurationRequest $request, Project $project, int $type): AnonymousResourceCollection
    {
        $associations = $this->configuration->write($request->user(), $project, function ($project) use ($request, $type) {
            $type = $project->noteTypes()->findOrFail($type);
            $this->configuration->reorderFields($type, $request->validated('ids'));

            return $type->fields()->with('field')->get();
        });

        return ProjectNoteTypeFieldResource::collection($associations);
    }
}
