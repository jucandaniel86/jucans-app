<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Project\ProjectCustomFieldSeriesRequest;
use App\Models\Project;
use App\Services\ProjectCustomFieldSeriesService;
use Illuminate\Http\JsonResponse;

class ProjectCustomFieldSeriesController extends Controller
{
    public function __invoke(ProjectCustomFieldSeriesRequest $request, Project $project, int $field, ProjectCustomFieldSeriesService $series): JsonResponse
    {
        return response()->json(['data' => $series->report($request->user(), $project, $field, $request->validated())]);
    }
}
