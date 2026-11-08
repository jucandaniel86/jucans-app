<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Services\ProjectTaskCalendarService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ProjectTaskCalendarController extends Controller
{
    public function index(Request $request, Project $project, ProjectTaskCalendarService $calendar): JsonResponse
    {
        Gate::authorize('view', $project);
        $input = $request->validate([
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date'],
        ]);
        if (CarbonImmutable::parse($input['start_date'])->diffInDays(CarbonImmutable::parse($input['end_date'])) > 30) {
            throw ValidationException::withMessages(['end_date' => 'Calendar ranges may cover at most 31 dates.']);
        }

        return response()->json($calendar->report($request->user(), $project, $input['start_date'], $input['end_date']));
    }
}
