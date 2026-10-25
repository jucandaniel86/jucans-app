<?php

  use App\Http\Controllers\Api\Admin\AuthorController;
  use App\Http\Controllers\Api\Admin\AdminIngredientController;
  use App\Http\Controllers\Api\Admin\MeditationController;
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
  use App\Http\Controllers\Api\ProjectCategoryController;
  use App\Http\Controllers\Api\ProjectController;
  use App\Http\Controllers\Api\ProjectCollaboratorController;
  use App\Http\Controllers\Api\ProjectTaskController;
  use App\Http\Controllers\Api\ProjectTaskExecutionController;
  use App\Http\Controllers\Api\ProjectTaskScheduleController;
  use App\Http\Controllers\Api\ProjectProgressNoteController;
  use App\Http\Controllers\Api\ProjectTaskEvaluationController;
  use App\Http\Controllers\Api\ProjectNoteTemplateController;
  use App\Http\Controllers\Api\ProjectProgressController;
  use App\Http\Controllers\Api\ProjectDailyTasksController;

  Route::post('/auth/login', [AuthController::class, 'login']);

  Route::middleware('auth:sanctum')->group(function () {
    Route::get('/project-categories', [ProjectCategoryController::class, 'index']);
    Route::get('/projects/daily-tasks', ProjectDailyTasksController::class);
    Route::get('/projects', [ProjectController::class, 'index']);
    Route::post('/projects', [ProjectController::class, 'store']);
    Route::get('/projects/{project}', [ProjectController::class, 'show'])->whereNumber('project');
    Route::patch('/projects/{project}', [ProjectController::class, 'update'])->whereNumber('project');
    Route::patch('/projects/{project}/status', [ProjectController::class, 'status'])->whereNumber('project');
    Route::prefix('projects/{project}')->whereNumber('project')->scopeBindings()->group(function () {
      Route::get('/progress/summary', [ProjectProgressController::class, 'summary']);
      Route::get('/progress/timeline', [ProjectProgressController::class, 'timeline']);
      Route::get('/progress/tasks/{task}', [ProjectProgressController::class, 'task'])->whereNumber('task');
      Route::post('/note-template/preview', [ProjectNoteTemplateController::class, 'preview']);
      Route::get('/notes/trash', [ProjectProgressNoteController::class, 'trash']);
      Route::get('/notes', [ProjectProgressNoteController::class, 'index']);
      Route::post('/notes', [ProjectProgressNoteController::class, 'store']);
      Route::get('/notes/{note}', [ProjectProgressNoteController::class, 'show'])->whereNumber('note');
      Route::patch('/notes/{note}', [ProjectProgressNoteController::class, 'update'])->whereNumber('note');
      Route::delete('/notes/{note}', [ProjectProgressNoteController::class, 'destroy'])->whereNumber('note');
      Route::post('/notes/{note}/restore', [ProjectProgressNoteController::class, 'restore'])->whereNumber('note')->withTrashed();
      Route::get('/collaborators', [ProjectCollaboratorController::class, 'index']);
      Route::post('/collaborators', [ProjectCollaboratorController::class, 'store']);
      Route::patch('/collaborators/{collaborator}', [ProjectCollaboratorController::class, 'update'])->whereNumber('collaborator');
      Route::delete('/collaborators/{collaborator}', [ProjectCollaboratorController::class, 'destroy'])->whereNumber('collaborator');
      Route::put('/collaborators/{collaborator}/tasks', [ProjectCollaboratorController::class, 'grants'])->whereNumber('collaborator');
      Route::get('/tasks/deleted', [ProjectTaskController::class, 'deleted']);
      Route::get('/tasks', [ProjectTaskController::class, 'index']);
      Route::post('/tasks', [ProjectTaskController::class, 'store']);
      Route::get('/tasks/{task}', [ProjectTaskController::class, 'show'])->whereNumber('task');
      Route::patch('/tasks/{task}', [ProjectTaskController::class, 'update'])->whereNumber('task');
      Route::delete('/tasks/{task}', [ProjectTaskController::class, 'destroy'])->whereNumber('task');
      Route::post('/tasks/{task}/restore', [ProjectTaskController::class, 'restore'])->whereNumber('task')->withTrashed();
      Route::prefix('tasks/{task}')->whereNumber('task')->group(function () {
        Route::get('/evaluations', [ProjectTaskEvaluationController::class, 'index']);
        Route::post('/evaluations', [ProjectTaskEvaluationController::class, 'store']);
        Route::get('/evaluations/{evaluation}', [ProjectTaskEvaluationController::class, 'show'])->whereNumber('evaluation');
        Route::patch('/evaluations/{evaluation}', [ProjectTaskEvaluationController::class, 'update'])->whereNumber('evaluation');
        Route::delete('/evaluations/{evaluation}', [ProjectTaskEvaluationController::class, 'destroy'])->whereNumber('evaluation');
        Route::get('/evaluations/{evaluation}/revisions', [ProjectTaskEvaluationController::class, 'revisions'])->whereNumber('evaluation');
        Route::post('/schedule', [ProjectTaskScheduleController::class, 'store']);
        Route::patch('/schedule', [ProjectTaskScheduleController::class, 'update']);
        Route::get('/schedule', [ProjectTaskScheduleController::class, 'show']);
        Route::get('/schedule-versions', [ProjectTaskScheduleController::class, 'index']);
        Route::get('/occurrences', [ProjectTaskExecutionController::class, 'index']);
        Route::get('/execution-history', [ProjectTaskExecutionController::class, 'history']);
        Route::post('/complete', [ProjectTaskExecutionController::class, 'complete']);
        Route::get('/occurrences/{occurrenceKey}', [ProjectTaskExecutionController::class, 'show']);
        Route::post('/occurrences/{occurrenceKey}/done', [ProjectTaskExecutionController::class, 'done']);
        Route::post('/occurrences/{occurrenceKey}/undo', [ProjectTaskExecutionController::class, 'undo']);
        Route::post('/occurrences/{occurrenceKey}/skip', [ProjectTaskExecutionController::class, 'skip']);
      });
    });
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
    Route::get('/authors', [AuthorController::class, 'index']);
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
      Route::get('/', [DietController::class, 'index']);
      Route::post('/', [DietController::class, 'store']);

      #daily structure
      Route::get('/daily-structures', [DietController::class, 'dailyStructures']);
      Route::post('/{diet}/daily-structure', [DietController::class, 'addDailyStructure']);
      Route::patch('/{diet}/daily-structure/{item}/order', [DietController::class, 'changeDailyStructureOrder']);
      Route::delete('/{diet}/daily-structure/{item}', [DietController::class, 'deleteDailyStructure']);

      #show
      Route::get('/{diet}', [DietController::class, 'show']);

      #sources
      Route::post('/{diet}/sources', [DietController::class, 'addDietSource']);
      Route::patch('/{diet}/sources/{sourceId}', [DietController::class, 'updateDietSource']);
      Route::delete('/{diet}/sources/{sourceId}', [DietController::class, 'deleteDietSource']);

      #recipes
      Route::post('/{diet}/recipes', [DietController::class, 'addDietRecipe']);
      Route::patch('/{diet}/recipes/{recipeId}', [DietController::class, 'updateDietRecipe']);
      Route::delete('/{diet}/recipes/{recipeId}', [DietController::class, 'deleteDietRecipe']);

      #ingredients
      Route::post('/{diet}/ingredients', [DietController::class, 'addDietIngredient',]);
      Route::patch('/{diet}/ingredients/{ingredientId}', [DietController::class, 'updateDietIngredient',]);
      Route::delete('/{diet}/ingredients/{ingredientId}', [DietController::class, 'deleteDietIngredient',]);
    });

    Route::prefix('admin')->middleware('admin')->group(function () {
      Route::get('/meditations', [MeditationController::class, 'index']);
      Route::apiResource('authors', AuthorController::class);
      Route::get('/ingredients', [AdminIngredientController::class, 'index']);
      Route::patch('/ingredients/{ingredient}', [AdminIngredientController::class, 'update']);
      Route::post('/ingredients/{source}/merge-preview', [AdminIngredientController::class, 'mergePreview']);
      Route::post('/ingredients/{source}/merge', [AdminIngredientController::class, 'merge']);
      Route::get('/recipe-ingredient-reviews', [RecipeIngredientReviewController::class, 'index']);
      Route::get('/shopping-categories', [ShoppingCategoryController::class, 'index']);
    });
  });
