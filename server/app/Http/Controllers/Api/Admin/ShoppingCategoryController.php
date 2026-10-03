<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\ShoppingCategoryResource;
use App\Models\ShoppingCategory;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ShoppingCategoryController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return ShoppingCategoryResource::collection(
            ShoppingCategory::query()->orderBy('sort_order')->orderBy('name')->get()
        );
    }
}
