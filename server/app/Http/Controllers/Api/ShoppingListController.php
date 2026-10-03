<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ShoppingList\UpdateShoppingListItemRequest;
use App\Http\Resources\ShoppingListItemResource;
use App\Http\Resources\ShoppingListRecipeResource;
use App\Http\Resources\ShoppingListResource;
use App\Models\Recipe;
use App\Models\ShoppingList;
use App\Models\ShoppingListItem;
use App\Services\ShoppingListRecipeService;
use App\Services\ShoppingListService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ShoppingListController extends Controller
{
    public function __construct(private readonly ShoppingListService $lists) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $lists = $this->lists->summaryQuery($request->user())
            ->orderByRaw("CASE WHEN status = 'open' THEN 0 ELSE 1 END")
            ->orderByDesc('closed_at')->orderByDesc('id')
            ->paginate($validated['per_page'] ?? 20)->withQueryString();

        return ShoppingListResource::collection($lists);
    }

    public function show(Request $request, ShoppingList $shoppingList): ShoppingListResource
    {
        $list = $this->lists->accessibleListQuery($request->user())->whereKey($shoppingList->id)->firstOrFail();

        return new ShoppingListResource($this->lists->loadSummaryCounts($list)->load(['items.shoppingCategory', 'items.sources']));
    }

    public function close(Request $request): ShoppingListResource|JsonResponse
    {
        $list = $this->lists->closeActive($request->user());

        return $list === null ? response()->json(null, 204) : new ShoppingListResource($list);
    }

    public function active(Request $request): ShoppingListResource|JsonResponse
    {
        $shoppingList = $this->lists->activeListQuery($request->user())->first();

        if ($shoppingList === null) {
            return response()->json(['data' => null]);
        }

        return new ShoppingListResource($this->lists->loadSummaryCounts($shoppingList)->load('items.shoppingCategory'));
    }

    public function store(Request $request): JsonResponse
    {
        [$shoppingList, $created] = $this->lists->getOrCreateActive($request->user());

        return (new ShoppingListResource($this->lists->loadSummaryCounts($shoppingList)))
            ->response()
            ->setStatusCode($created ? 201 : 200);
    }

    public function addRecipe(Request $request, Recipe $recipe, ShoppingListRecipeService $service): JsonResponse
    {
        $result = $service->add($request->user(), $recipe);

        return (new ShoppingListRecipeResource($result))->response()
            ->setStatusCode($result['already_present'] ? 200 : 201);
    }

    public function updateItem(UpdateShoppingListItemRequest $request, ShoppingListItem $item): ShoppingListItemResource
    {
        return new ShoppingListItemResource($this->lists->updateItemChecked(
            $request->user(), $item, (bool) $request->validated('is_checked')
        ));
    }
}
