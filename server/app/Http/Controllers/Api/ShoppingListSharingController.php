<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ShoppingList\AttachShoppingListUserRequest;
use App\Http\Requests\ShoppingList\UpdateShoppingListVisibilityRequest;
use App\Http\Resources\ShoppingListResource;
use App\Http\Resources\ShoppingListUserResource;
use App\Models\ShoppingList;
use App\Models\User;
use App\Services\ShoppingListService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class ShoppingListSharingController extends Controller
{
    public function __construct(private readonly ShoppingListService $lists) {}

    public function visibility(UpdateShoppingListVisibilityRequest $request, ShoppingList $shoppingList): ShoppingListResource
    {
        return new ShoppingListResource($this->lists->changeVisibility(
            $request->user(), $shoppingList, $request->validated('visibility')
        ));
    }

    public function index(Request $request, ShoppingList $shoppingList): AnonymousResourceCollection
    {
        Gate::authorize('manageSharing', $shoppingList);

        return ShoppingListUserResource::collection($shoppingList->users()->orderBy('users.id')->get(['users.id', 'users.username', 'users.avatar']));
    }

    public function store(AttachShoppingListUserRequest $request, ShoppingList $shoppingList): JsonResponse
    {
        $member = User::query()->findOrFail($request->validated('user_id'));
        $created = $this->lists->attachUser($request->user(), $shoppingList, $member);

        return (new ShoppingListUserResource($member))->response()->setStatusCode($created ? 201 : 200);
    }

    public function destroy(Request $request, ShoppingList $shoppingList, User $user): JsonResponse
    {
        $this->lists->detachUser($request->user(), $shoppingList, $user);

        return response()->json(null, 204);
    }
}
