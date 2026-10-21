<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreAuthorRequest;
use App\Http\Requests\Admin\UpdateAuthorRequest;
use App\Http\Resources\AuthorResource;
use App\Models\Author;
use App\Services\RecipeImageStorage;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Arr;
use Throwable;

class AuthorController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return AuthorResource::collection(Author::query()->orderBy('name')->orderBy('id')->get());
    }

    public function show(Author $author): AuthorResource
    {
        return new AuthorResource($author);
    }

    public function store(StoreAuthorRequest $request, RecipeImageStorage $images): AuthorResource
    {
        $icon = $request->hasFile('icon') ? $images->store($request->file('icon'), 'authors') : null;
        try {
            $author = Author::create([
                ...Arr::only($request->validated(), ['name', 'youtube_link', 'website_link']),
                'icon' => $icon,
            ]);
        } catch (Throwable $exception) {
            $images->deleteOwned($icon, 'authors');
            throw $exception;
        }

        return new AuthorResource($author);
    }

    public function update(UpdateAuthorRequest $request, Author $author, RecipeImageStorage $images): AuthorResource
    {
        $oldIcon = $author->icon;
        $icon = $request->hasFile('icon') ? $images->store($request->file('icon'), 'authors') : null;
        $attributes = Arr::only($request->validated(), ['name', 'youtube_link', 'website_link']);
        if ($icon !== null || $request->boolean('remove_icon')) {
            $attributes['icon'] = $icon;
        }
        try {
            $author->update($attributes);
        } catch (Throwable $exception) {
            $images->deleteOwned($icon, 'authors');
            throw $exception;
        }
        if ($oldIcon !== $author->icon) {
            $images->deleteOwned($oldIcon, 'authors');
        }

        return new AuthorResource($author);
    }

    public function destroy(Author $author, RecipeImageStorage $images): Response
    {
        $icon = $author->icon;
        $author->delete();
        $images->deleteOwned($icon, 'authors');

        return response()->noContent();
    }
}
