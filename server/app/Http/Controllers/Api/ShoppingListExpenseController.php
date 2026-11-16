<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ShoppingList\RegisterShoppingExpenseRequest;
use App\Models\ShoppingList;
use App\Services\ShoppingListExpenseService;
use Illuminate\Http\JsonResponse;

class ShoppingListExpenseController extends Controller
{
    public function __invoke(RegisterShoppingExpenseRequest $request, ShoppingList $shoppingList, ShoppingListExpenseService $expenses): JsonResponse
    {
        $result = $expenses->register($request->user(), $shoppingList, $request->validated());
        return response()->json(['data' => $result['data'], 'already_registered' => ! $result['created']], $result['created'] ? 201 : 200);
    }
}
