<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Project\StoreProjectTaskEvaluationRequest;
use App\Http\Requests\Project\UpdateProjectTaskEvaluationRequest;
use App\Http\Resources\ProjectTaskEvaluationResource;
use App\Http\Resources\ProjectTaskEvaluationRevisionResource;
use App\Models\Project;
use App\Models\ProjectProgressNote;
use App\Models\ProjectTask;
use App\Models\ProjectTaskEvaluation;
use App\Services\ProjectTaskEvaluationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class ProjectTaskEvaluationController extends Controller
{
    public function __construct(private readonly ProjectTaskEvaluationService $evaluations) {}

    public function index(Request $request, Project $project, ProjectTask $task): AnonymousResourceCollection
    {
        Gate::authorize('view', $task);
        $input = $request->validate([
            'from' => ['sometimes', 'required', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'required', 'date_format:Y-m-d', ...($request->filled('from') ? ['after_or_equal:from'] : [])],
            ...$this->paginationRules(),
        ]);
        $query = $task->evaluations()->active()->visibleTo($request->user());
        if (isset($input['from'])) {
            $query->where('evaluation_date', '>=', $input['from']);
        }
        if (isset($input['to'])) {
            $query->where('evaluation_date', '<=', $input['to']);
        }

        return ProjectTaskEvaluationResource::collection($query->orderByDesc('evaluation_date')->orderByDesc('id')
            ->paginate($input['per_page'] ?? 20)->withQueryString());
    }

    public function store(StoreProjectTaskEvaluationRequest $request, Project $project, ProjectTask $task): JsonResponse
    {
        return (new ProjectTaskEvaluationResource($this->evaluations->create($request->user(), $project, $task, $request->validated())))
            ->response()->setStatusCode(201);
    }

    public function show(Request $request, Project $project, ProjectTask $task, ProjectTaskEvaluation $evaluation): ProjectTaskEvaluationResource
    {
        Gate::authorize('view', $evaluation);

        return new ProjectTaskEvaluationResource($evaluation);
    }

    public function update(UpdateProjectTaskEvaluationRequest $request, Project $project, ProjectTask $task, ProjectTaskEvaluation $evaluation): ProjectTaskEvaluationResource
    {
        return new ProjectTaskEvaluationResource($this->evaluations->update($request->user(), $project, $task, $evaluation, $request->validated()));
    }

    public function destroy(UpdateProjectTaskEvaluationRequest $request, Project $project, ProjectTask $task, ProjectTaskEvaluation $evaluation): JsonResponse
    {
        $this->evaluations->update($request->user(), $project, $task, $evaluation, $request->validated(), true);

        return response()->json(null, 204);
    }

    public function revisions(Request $request, Project $project, ProjectTask $task, ProjectTaskEvaluation $evaluation): AnonymousResourceCollection
    {
        Gate::authorize('view', $evaluation);
        $input = $request->validate($this->paginationRules());
        $query = $evaluation->revisions();
        if (! $project->isOwnedBy($request->user())) {
            $visibleNotes = ProjectProgressNote::query()->visibleTo($request->user())->select('project_progress_notes.id');
            foreach (['previous_note_id', 'new_note_id'] as $field) {
                $query->where(fn ($query) => $query->whereNull($field)->orWhereIn($field, $visibleNotes));
            }
        }

        return ProjectTaskEvaluationRevisionResource::collection($query->orderByDesc('id')
            ->paginate($input['per_page'] ?? 20)->withQueryString());
    }

    private function paginationRules(): array
    {
        return ['page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100']];
    }
}
