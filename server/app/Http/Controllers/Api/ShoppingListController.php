<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ShoppingList\StoreManualShoppingListItemRequest;
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

        return new ShoppingListResource($this->lists->loadSummaryCounts($list, $request->user())->load([
            'items.shoppingCategory',
            'items.sources',
            'recipes' => fn ($query) => $query->orderBy('recipes.id'),
        ]));
    }

    public function open(Request $request): AnonymousResourceCollection
    {
        return ShoppingListResource::collection($this->lists->summaryQuery($request->user())
            ->where('status', 'open')->orderBy('id')->get());
    }

    public function closeList(Request $request, ShoppingList $shoppingList): ShoppingListResource
    {
        return new ShoppingListResource($this->lists->closeList($request->user(), $shoppingList));
    }

    public function close(Request $request): ShoppingListResource|JsonResponse
    {
        $list = $this->lists->closeActive($request->user());

        return $list === null ? response()->json(null, 204) : new ShoppingListResource($list);
    }

    public function active(Request $request): ShoppingListResource|JsonResponse
    {
        $shoppingList = $this->lists->resolveActiveList($request->user());

        if ($shoppingList === null) {
            return response()->json(['data' => null]);
        }

        return new ShoppingListResource($this->lists->loadSummaryCounts($shoppingList, $request->user())->load([
            'items.shoppingCategory',
            'items.sources',
            'recipes' => fn ($query) => $query->orderBy('recipes.id'),
        ]));
    }

    public function store(Request $request): JsonResponse
    {
        [$shoppingList, $created] = $this->lists->getOrCreateOwnedOpen($request->user());

        return (new ShoppingListResource($this->lists->loadSummaryCounts($shoppingList, $request->user())))
            ->response()
            ->setStatusCode($created ? 201 : 200);
    }

    public function addRecipe(Request $request, Recipe $recipe, ShoppingListRecipeService $service): JsonResponse
    {
        $result = $service->add($request->user(), $recipe);

        return (new ShoppingListRecipeResource($result))->response()
            ->setStatusCode($result['already_present'] ? 200 : 201);
    }

    public function addRecipeToList(Request $request, ShoppingList $shoppingList, Recipe $recipe, ShoppingListRecipeService $service): JsonResponse
    {
        $result = $service->add($request->user(), $recipe, $shoppingList);

        return (new ShoppingListRecipeResource($result))->response()
            ->setStatusCode($result['already_present'] ? 200 : 201);
    }

    public function storeListItem(StoreManualShoppingListItemRequest $request, ShoppingList $shoppingList): JsonResponse
    {
        return (new ShoppingListItemResource($this->lists->addManualItem(
            $request->user(), $request->validated(), $shoppingList
        )))->response()->setStatusCode(201);
    }

    public function updateListItem(UpdateShoppingListItemRequest $request, ShoppingList $shoppingList, ShoppingListItem $item): ShoppingListItemResource
    {
        return new ShoppingListItemResource($this->lists->updateItem(
            $request->user(), $item, $request->validated(), $shoppingList
        ));
    }

    public function destroyListItem(Request $request, ShoppingList $shoppingList, ShoppingListItem $item): JsonResponse
    {
        $this->lists->deleteManualItem($request->user(), $item, $shoppingList);

        return response()->json(null, 204);
    }

    public function export(Request $request, ShoppingList $shoppingList): JsonResponse|\Symfony\Component\HttpFoundation\Response
    {
        $text = $this->lists->exportUnchecked($request->user(), $shoppingList);
        if ($text === null) {
            return response()->json(null, 204);
        }

        return response($text, 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="lista-cumparaturi.txt"',
        ]);
    }

    public function storeItem(StoreManualShoppingListItemRequest $request): JsonResponse
    {
        return (new ShoppingListItemResource($this->lists->addManualItem(
            $request->user(), $request->validated()
        )))->response()->setStatusCode(201);
    }

    public function removeRecipe(Request $request, ShoppingList $shoppingList, Recipe $recipe, ShoppingListRecipeService $service): ShoppingListResource
    {
        return new ShoppingListResource($service->remove($request->user(), $shoppingList, $recipe));
    }

    public function updateItem(UpdateShoppingListItemRequest $request, ShoppingListItem $item): ShoppingListItemResource
    {
        return new ShoppingListItemResource($this->lists->updateItem(
            $request->user(), $item, $request->validated()
        ));
    }
}
