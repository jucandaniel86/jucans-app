<?php

	use App\Http\Controllers\Api\Admin\AdminIngredientController;
	use App\Http\Controllers\Api\Admin\RecipeIngredientReviewController;
	use App\Http\Controllers\Api\Admin\ShoppingCategoryController;
	use App\Http\Controllers\Api\{AuthController,
		DietController,
		FoodConfigController,
		FoodTagController,
		IngredientAliasController,
		IngredientController,
		IngredientResolverController,
		RecipeController,
		ShoppingListController,
		ShoppingListSharingController
	};
	use Illuminate\Support\Facades\Route;

	Route::post('/auth/login', [AuthController::class, 'login']);

	Route::middleware('auth:sanctum')->group(function () {
		Route::get('/auth/me', [AuthController::class, 'me']);
		Route::patch('/auth/avatar', [AuthController::class, 'updateAvatar']);
		Route::post('/auth/logout', [AuthController::class, 'logout']);

		Route::get('/config/food', FoodConfigController::class);
		Route::get('/food-tags', [FoodTagController::class, 'index']);
		Route::post('/food-tags', [FoodTagController::class, 'store']);
		Route::patch('/food-tags/{foodTag}', [FoodTagController::class, 'update']);
		Route::post('/ingredients/resolve', IngredientResolverController::class);
		Route::get('/ingredients/search', [IngredientController::class, 'search']);
		Route::post('/ingredients/aliases', [IngredientAliasController::class, 'store']);
		Route::apiResource('recipes', RecipeController::class);
		Route::get('/shopping-lists/active', [ShoppingListController::class, 'active']);
		Route::get('/shopping-lists/open', [ShoppingListController::class, 'open']);
		Route::get('/shopping-lists/shareable-users', [ShoppingListSharingController::class, 'shareableUsers']);
		Route::get('/shopping-lists', [ShoppingListController::class, 'index']);
		Route::post('/shopping-lists/active/close', [ShoppingListController::class, 'close']);
		Route::get('/shopping-lists/{shoppingList}', [ShoppingListController::class, 'show']);
		Route::post('/shopping-lists', [ShoppingListController::class, 'store']);
		Route::post('/shopping-lists/{shoppingList}/close', [ShoppingListController::class, 'closeList'])->whereNumber('shoppingList');
		Route::get('/shopping-lists/{shoppingList}/export', [ShoppingListController::class, 'export'])->whereNumber('shoppingList');
		Route::post('/shopping-lists/{shoppingList}/recipes/{recipe}', [ShoppingListController::class, 'addRecipeToList'])->whereNumber('shoppingList');
		Route::post('/shopping-lists/{shoppingList}/items', [ShoppingListController::class, 'storeListItem'])->whereNumber('shoppingList');
		Route::patch('/shopping-lists/{shoppingList}/items/{item}', [ShoppingListController::class, 'updateListItem'])->whereNumber('shoppingList');
		Route::delete('/shopping-lists/{shoppingList}/items/{item}', [ShoppingListController::class, 'destroyListItem'])->whereNumber('shoppingList');
		Route::post('/shopping-lists/active/recipes/{recipe}', [ShoppingListController::class, 'addRecipe']);
		Route::delete('/shopping-lists/{shoppingList}/recipes/{recipe}', [ShoppingListController::class, 'removeRecipe']);
		Route::post('/shopping-lists/active/items', [ShoppingListController::class, 'storeItem']);
		Route::patch('/shopping-lists/active/items/{item}', [ShoppingListController::class, 'updateItem']);
		Route::patch('/shopping-lists/{shoppingList}/visibility', [ShoppingListSharingController::class, 'visibility']);
		Route::get('/shopping-lists/{shoppingList}/users', [ShoppingListSharingController::class, 'index']);
		Route::post('/shopping-lists/{shoppingList}/users', [ShoppingListSharingController::class, 'store']);
		Route::delete('/shopping-lists/{shoppingList}/users/{user}', [ShoppingListSharingController::class, 'destroy']);

		Route::prefix('diets')->group(function () {
			Route::post('/', [DietController::class, 'store']);
			Route::get('/daily-structures', [DietController::class, 'dailyStructures']);

			Route::post('/{diet}/daily-structure', [DietController::class, 'addDailyStructure']);
			Route::patch(
				'/{diet}/daily-structure/{item}/order',
				[DietController::class, 'changeDailyStructureOrder']
			);
			Route::delete(
				'/{diet}/daily-structure/{item}',
				[DietController::class, 'deleteDailyStructure']
			);
			Route::get('/{diet}', [DietController::class, 'show']);

		});

		Route::prefix('admin')->middleware('admin')->group(function () {
			Route::get('/ingredients', [AdminIngredientController::class, 'index']);
			Route::patch('/ingredients/{ingredient}', [AdminIngredientController::class, 'update']);
			Route::post('/ingredients/{source}/merge-preview', [AdminIngredientController::class, 'mergePreview']);
			Route::post('/ingredients/{source}/merge', [AdminIngredientController::class, 'merge']);
			Route::get('/recipe-ingredient-reviews', [RecipeIngredientReviewController::class, 'index']);
			Route::get('/shopping-categories', [ShoppingCategoryController::class, 'index']);
		});
	});