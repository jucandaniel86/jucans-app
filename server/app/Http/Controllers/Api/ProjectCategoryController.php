<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProjectCategoryResource;
use App\Models\ProjectCategory;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ProjectCategoryController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return ProjectCategoryResource::collection(ProjectCategory::query()->orderBy('sort_order')->orderBy('id')->get());
    }
}
