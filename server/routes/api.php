<?php

use App\Http\Controllers\Api\Admin\AdminIngredientController;
use App\Http\Controllers\Api\Admin\RecipeIngredientReviewController;
use App\Http\Controllers\Api\Admin\ShoppingCategoryController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\FoodConfigController;
use App\Http\Controllers\Api\FoodTagController;
use App\Http\Controllers\Api\IngredientAliasController;
use App\Http\Controllers\Api\IngredientController;
use App\Http\Controllers\Api\IngredientResolverController;
use App\Http\Controllers\Api\RecipeController;
use App\Http\Controllers\Api\ShoppingListController;
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
    Route::get('/shopping-lists', [ShoppingListController::class, 'index']);
    Route::post('/shopping-lists/active/close', [ShoppingListController::class, 'close']);
    Route::get('/shopping-lists/{shoppingList}', [ShoppingListController::class, 'show']);
    Route::post('/shopping-lists', [ShoppingListController::class, 'store']);
    Route::post('/shopping-lists/active/recipes/{recipe}', [ShoppingListController::class, 'addRecipe']);
    Route::patch('/shopping-lists/active/items/{item}', [ShoppingListController::class, 'updateItem']);

    Route::prefix('admin')->middleware('admin')->group(function () {
        Route::get('/ingredients', [AdminIngredientController::class, 'index']);
        Route::patch('/ingredients/{ingredient}', [AdminIngredientController::class, 'update']);
        Route::post('/ingredients/{source}/merge-preview', [AdminIngredientController::class, 'mergePreview']);
        Route::post('/ingredients/{source}/merge', [AdminIngredientController::class, 'merge']);
        Route::get('/recipe-ingredient-reviews', [RecipeIngredientReviewController::class, 'index']);
        Route::get('/shopping-categories', [ShoppingCategoryController::class, 'index']);
    });
});
