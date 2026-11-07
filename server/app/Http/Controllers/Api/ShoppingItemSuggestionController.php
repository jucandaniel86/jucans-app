<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ShoppingItemSuggestionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ShoppingItemSuggestionController extends Controller
{
    public function index(Request $request, ShoppingItemSuggestionService $suggestions): JsonResponse
    {
        $input = $request->validate(['query' => ['nullable', 'string', 'max:100']]);

        return response()->json(['data' => $suggestions->search($request->user(), $input['query'] ?? '')]);
    }
}
