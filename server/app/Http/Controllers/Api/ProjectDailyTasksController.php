<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Project\ListDailyProjectTasksRequest;
use App\Services\ProjectDailyTasksService;
use Illuminate\Http\Resources\Json\JsonResource;

class ProjectDailyTasksController extends Controller
{
    public function __invoke(ListDailyProjectTasksRequest $request, ProjectDailyTasksService $daily): JsonResource
    {
        return new JsonResource($daily->report($request->user(), $request->validated()));
    }
}
