<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Project\PreviewProjectNoteTemplateRequest;
use App\Models\Project;
use App\Services\ProjectProgressNoteService;
use Illuminate\Http\JsonResponse;

class ProjectNoteTemplateController extends Controller
{
    public function preview(PreviewProjectNoteTemplateRequest $request, Project $project, ProjectProgressNoteService $notes): JsonResponse
    {
        $project = Project::query()->accessibleTo($request->user())->whereKey($project->id)->firstOrFail();
        $input = $request->validated();
        $ids = $notes->accessibleTaskIds($request->user(), $project, $input['task_ids'] ?? []);
        $titles = $project->tasks()->visibleTo($request->user())->whereIn('project_tasks.id', $ids)->orderBy('id')->pluck('title');

        return response()->json(['data' => ['content' => strtr($project->note_template ?? '', [
            '{date}' => $input['date'] ?? now($project->getAttributes()['timezone'] ?? config('app.timezone'))->toDateString(),
            '{tasks}' => $titles->implode("\n"),
            '{project}' => $project->name,
            '{user}' => $request->user()->username,
        ])]]);
    }
}
