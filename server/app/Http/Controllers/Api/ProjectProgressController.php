<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Project\ProjectProgressRequest;
use App\Http\Resources\ProjectProgressResource;
use App\Models\Project;
use App\Models\ProjectTask;
use App\Services\ProjectProgressService;

class ProjectProgressController extends Controller
{
    public function __construct(private readonly ProjectProgressService $progress) {}

    public function summary(ProjectProgressRequest $request, Project $project): ProjectProgressResource
    {
        return new ProjectProgressResource($this->progress->report($request->user(), $project, $request->validated(), true));
    }

    public function timeline(ProjectProgressRequest $request, Project $project): ProjectProgressResource
    {
        return new ProjectProgressResource($this->progress->report($request->user(), $project, $request->validated()));
    }

    public function task(ProjectProgressRequest $request, Project $project, ProjectTask $task): ProjectProgressResource
    {
        abort_if($request->has('task_id') && (int) $request->input('task_id') !== $task->id, 422, 'Conflicting task filters.');

        return new ProjectProgressResource($this->progress->report($request->user(), $project,
            array_merge($request->validated(), ['task_id' => $task->id]), false, true));
    }
}
