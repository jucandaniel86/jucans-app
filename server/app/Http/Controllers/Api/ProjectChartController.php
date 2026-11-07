<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Project\ProjectChartDataRequest;
use App\Http\Requests\Project\ProjectConfigurationRequest;
use App\Http\Requests\Project\SaveProjectChartRequest;
use App\Http\Resources\ProjectChartResource;
use App\Models\Project;
use App\Services\ProjectChartConfigurationService;
use App\Services\ProjectChartReportService;
use App\Services\ProjectNoteConfigurationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class ProjectChartController extends Controller
{
    public function index(Request $request, Project $project): AnonymousResourceCollection
    {
        Gate::authorize('view', $project);

        return ProjectChartResource::collection($project->charts()->with(['fields', 'pieValueField'])->get());
    }

    public function store(SaveProjectChartRequest $request, Project $project, ProjectNoteConfigurationService $writer, ProjectChartConfigurationService $charts): JsonResponse
    {
        $chart = $writer->write($request->user(), $project, fn ($project) => $charts->save($project, $request->validated()));

        return (new ProjectChartResource($chart))->response()->setStatusCode(201);
    }

    public function update(SaveProjectChartRequest $request, Project $project, int $chart, ProjectNoteConfigurationService $writer, ProjectChartConfigurationService $charts): ProjectChartResource
    {
        return new ProjectChartResource($writer->write($request->user(), $project,
            fn ($project) => $charts->save($project, $request->validated(), $project->charts()->findOrFail($chart))));
    }

    public function destroy(ProjectConfigurationRequest $request, Project $project, int $chart, ProjectNoteConfigurationService $writer): JsonResponse
    {
        $writer->write($request->user(), $project, fn ($project) => $project->charts()->findOrFail($chart)->delete());

        return response()->json(null, 204);
    }

    public function data(ProjectChartDataRequest $request, Project $project, ProjectChartReportService $reports): JsonResponse
    {
        return response()->json(['data' => $reports->report($request->user(), $project, $request->validated())]);
    }
}
