<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\FoodTag\StoreFoodTagRequest;
use App\Http\Requests\FoodTag\UpdateFoodTagRequest;
use App\Http\Resources\FoodTagResource;
use App\Models\FoodTag;
use App\Support\NameNormalizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class FoodTagController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return FoodTagResource::collection(
            FoodTag::query()->withCount('recipes')->orderBy('normalized_name')->get()
        );
    }

    public function store(StoreFoodTagRequest $request): JsonResponse
    {
        $name = trim($request->validated('name'));
        $tag = FoodTag::query()->firstOrCreate(
            ['normalized_name' => NameNormalizer::normalize($name)],
            ['name' => $name, 'emoji' => $request->validated('emoji')]
        );

        return (new FoodTagResource($tag))
            ->response()
            ->setStatusCode($tag->wasRecentlyCreated ? 201 : 200);
    }

    public function update(UpdateFoodTagRequest $request, FoodTag $foodTag): FoodTagResource
    {
        $foodTag->update($request->validated());

        return new FoodTagResource($foodTag->refresh());
    }
}
