<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\MeditationResource;
use App\Models\Meditation;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class MeditationController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return MeditationResource::collection(
            Meditation::query()->orderBy('category')->orderBy('title')->orderBy('id')->get()
        );
    }
}
