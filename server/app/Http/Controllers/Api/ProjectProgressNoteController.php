<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Project\ListProjectProgressNotesRequest;
use App\Http\Requests\Project\StoreProjectProgressNoteRequest;
use App\Http\Requests\Project\UpdateProjectProgressNoteRequest;
use App\Http\Resources\ProjectProgressNoteResource;
use App\Models\Project;
use App\Models\ProjectProgressNote;
use App\Services\ProjectProgressNoteService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class ProjectProgressNoteController extends Controller
{
    public function __construct(private readonly ProjectProgressNoteService $notes) {}

    public function index(ListProjectProgressNotesRequest $request, Project $project): AnonymousResourceCollection
    {
        return $this->listing($request, $project);
    }

    public function trash(ListProjectProgressNotesRequest $request, Project $project): AnonymousResourceCollection
    {
        Gate::authorize('viewTrash', [ProjectProgressNote::class, $project]);

        return $this->listing($request, $project, true);
    }

    public function store(StoreProjectProgressNoteRequest $request, Project $project): JsonResponse
    {
        return (new ProjectProgressNoteResource($this->notes->create($request->user(), $project, $request->validated())))
            ->response()->setStatusCode(201);
    }

    public function show(Request $request, Project $project, ProjectProgressNote $note): ProjectProgressNoteResource
    {
        $note = $project->notes()->visibleTo($request->user())->whereKey($note->id)->firstOrFail();

        return new ProjectProgressNoteResource($note->load($this->notes->relations($request->user())));
    }

    public function update(UpdateProjectProgressNoteRequest $request, Project $project, ProjectProgressNote $note): ProjectProgressNoteResource
    {
        return new ProjectProgressNoteResource($this->notes->update($request->user(), $project, $note, $request->validated()));
    }

    public function destroy(Request $request, Project $project, ProjectProgressNote $note): JsonResponse
    {
        $this->notes->delete($request->user(), $project, $note);

        return response()->json(null, 204);
    }

    public function restore(Request $request, Project $project, ProjectProgressNote $note): ProjectProgressNoteResource
    {
        return new ProjectProgressNoteResource($this->notes->restore($request->user(), $project, $note));
    }

    private function listing(ListProjectProgressNotesRequest $request, Project $project, bool $trash = false): AnonymousResourceCollection
    {
        $input = $request->validated();
        $query = $project->notes()->visibleTo($request->user())->with($this->notes->relations($request->user(), $trash));
        if ($trash) {
            $query->onlyTrashed();
        }
        if ($search = $input['search'] ?? null) {
            $query->where(fn (Builder $query) => $query->where('title', 'like', '%'.$search.'%')->orWhere('content', 'like', '%'.$search.'%'));
        }
        if (isset($input['task_id'])) {
            $query->whereHas('tasks', fn (Builder $tasks) => $tasks->where('project_tasks.id', $input['task_id']));
        }
        if ($request->boolean('general_only')) {
            $query->whereDoesntHave('tasks');
        }
        if (isset($input['from'])) {
            $query->where('noted_at', '>=', $input['from']);
        }
        if (isset($input['to'])) {
            $query->where('noted_at', '<=', $input['to']);
        }

        return ProjectProgressNoteResource::collection($query->orderByDesc('noted_at')->orderByDesc('id')
            ->paginate($input['per_page'] ?? 20)->withQueryString());
    }
}
