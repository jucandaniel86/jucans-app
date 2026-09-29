<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\FoodConfigController;
use App\Http\Controllers\Api\FoodTagController;
use App\Http\Controllers\Api\IngredientAliasController;
use App\Http\Controllers\Api\IngredientController;
use App\Http\Controllers\Api\IngredientResolverController;
use App\Http\Controllers\Api\RecipeController;
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
});
