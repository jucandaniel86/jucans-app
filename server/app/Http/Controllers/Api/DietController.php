<?php

  namespace App\Http\Controllers\Api;

  use App\Enums\DietIngredientStatus;
  use App\Http\Controllers\Controller;
  use App\Http\Requests\Diet\AddDailyStructureRequest;
  use App\Http\Requests\Diet\AddDietIngredientRequest;
  use App\Http\Requests\Diet\ChangeDailyStructureOrderRequest;
  use App\Http\Requests\Diet\DietDraftRequest;
  use App\Http\Requests\Diet\SaveSourceRequest;
  use App\Http\Requests\Diet\UpdateDietIngredientRequest;
  use App\Http\Resources\DailyStructureResource;
  use App\Http\Resources\DietDailyStructureResource;
  use App\Http\Resources\DietIngredientResource;
  use App\Http\Resources\DietResource;
  use App\Http\Resources\DietSourceResource;
  use App\Models\DailyStructure;
  use App\Models\Diet;
  use App\Services\DietService;
  use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
  use Illuminate\Support\Facades\DB;
  use Illuminate\Validation\ValidationException;

  class DietController extends Controller
  {
    /**
     * @url POST /api/diets
     * @param DietDraftRequest $request
     * @param DietService $service
     * @return DietResource
     */
    public function store(
      DietDraftRequest $request,
      DietService      $service
    ): DietResource
    {
      $validated = $request->validated();

      $diet = $service->createDraft(
        $request->user(),
        $validated['name']
      );

      return DietResource::make(
        $this->loadDiet($diet)
      );
    }

    /**
     * @url GET /api/diets/{diet}
     * @param Diet $diet
     * @return DietResource
     */
    public function show(Diet $diet): DietResource
    {
      return DietResource::make(
        $this->loadDiet($diet)
      );
    }

    /**
     * @url GET /api/diets/daily-structures
     * @return \Illuminate\Http\Resources\Json\AnonymousResourceCollection
     */
    public function dailyStructures()
    {
      return DailyStructureResource::collection(DailyStructure::all());
    }

    private function loadDiet(Diet $diet): Diet
    {
      return $diet->load([
        'creator',
        'ingredients',
        'sources' => fn($q) => $q->orderBy('id', 'desc'),
        'dailyStructure' => fn($q) => $q->with('structure'),
      ]);
    }

    private function getDailyStructure(Diet $diet)
    {
      return $diet->dailyStructure()
        ->with('structure')
        ->orderBy('position')
        ->get();
    }

    /**
     * @url POST /diets/{diet}/daily-structure
     * @param AddDailyStructureRequest $request
     * @param Diet $diet
     * @return DietDailyStructureResource
     */
    public function addDailyStructure(AddDailyStructureRequest $request, Diet $diet): AnonymousResourceCollection
    {
      $position = ($diet->dailyStructure()->max('position') ?? 0) + 1;

      $diet->dailyStructure()->create([
        'daily_structure_id' => $request->validated('daily_structure_id'),
        'position' => $position,
      ]);

      return DietDailyStructureResource::collection(
        $this->getDailyStructure($diet)
      );
    }

    /**
     * @url /api/{diet}/daily-structure/{item}
     * @param ChangeDailyStructureOrderRequest $request
     * @param Diet $diet
     * @param int $itemId
     * @return AnonymousResourceCollection
     */
    public function changeDailyStructureOrder(
      ChangeDailyStructureOrderRequest $request,
      Diet                             $diet,
      int                              $itemId
    ): AnonymousResourceCollection
    {
      DB::transaction(function () use ($diet, $itemId, $request) {

        $item = $diet->dailyStructure()
          ->whereKey($itemId)
          ->firstOrFail();

        $targetPosition = match ($request->validated('direction')) {
          'up' => $item->position - 1,
          'down' => $item->position + 1,
          default => abort(422, 'Invalid direction.'),
        };

        $target = $diet->dailyStructure()
          ->where('position', $targetPosition)
          ->first();

        if (!$target) {
          return;
        }

        $currentPosition = $item->position;

        $item->update([
          'position' => $target->position,
        ]);

        $target->update([
          'position' => $currentPosition,
        ]);
      });

      return DietDailyStructureResource::collection(
        $this->getDailyStructure($diet)
      );
    }

    /**
     * @url DELETE /diets//{diet}/daily-structure/{item}
     * @param Diet $diet
     * @param int $itemId
     * @return AnonymousResourceCollection
     */
    public function deleteDailyStructure(
      Diet        $diet,
      int         $itemId,
      DietService $service
    ): AnonymousResourceCollection
    {
      DB::transaction(function () use ($diet, $itemId, $service) {

        $diet->dailyStructure()
          ->whereKey($itemId)
          ->firstOrFail()
          ->delete();

        $service->normalizeDailyStructurePositions($diet);
      });

      return DietDailyStructureResource::collection(
        $diet->dailyStructure()
          ->with('structure')
          ->orderBy('position')
          ->get()
      );
    }


    /**
     * @url POST /diets/{diet}/sources
     * @param SaveSourceRequest $request
     * @param Diet $diet
     * @return AnonymousResourceCollection
     */
    public function addDietSource(SaveSourceRequest $request, Diet $diet): AnonymousResourceCollection
    {
      $diet->sources()->create($request->validated());

      return DietSourceResource::collection($diet->sources()->orderBy('id', 'desc')->get());
    }

    /**
     * @url PATCH /diets/{diet}/sources/{sourceId}
     */

    public function updateDietSource(SaveSourceRequest $request, Diet $diet, int $sourceId): AnonymousResourceCollection
    {
      $source = $diet->sources()
        ->whereKey($sourceId)
        ->firstOrFail();

      $source->update($request->validated());

      return DietSourceResource::collection($diet->sources()->orderBy('id', 'desc')->get());
    }

    /**
     * @url DELETE /diets/{diet}/sources/{sourceId}
     */
    public function deleteDietSource(Diet $diet, int $sourceId): AnonymousResourceCollection
    {
      $source = $diet->sources()
        ->whereKey($sourceId)
        ->firstOrFail();

      $source->delete();

      return DietSourceResource::collection($diet->sources()->orderBy('id', 'desc')->get());
    }

    /**
     * @param Diet $diet
     * @return AnonymousResourceCollection
     */
    private function dietIngredientsResponse(
      Diet $diet
    ): AnonymousResourceCollection
    {
      return DietIngredientResource::collection(
        $diet->ingredients()
          ->orderBy('ingredients.name')
          ->get()
      );
    }

    /**
     * @url POST /diets/{diet}/ingredients
     */
    public function addDietIngredient(
      AddDietIngredientRequest $request,
      Diet                     $diet
    ): AnonymousResourceCollection
    {
      $validated = $request->validated();

      $ingredientId = $validated['ingredient_id'];

      if ($diet->ingredients()->whereKey($ingredientId)->exists()) {
        throw ValidationException::withMessages([
          'ingredient_id' => 'Ingredientul este deja asociat dietei.',
        ]);
      }

      $diet->ingredients()->attach($ingredientId, [
        'status' => $validated['status'] ?? DietIngredientStatus::ALLOWED->value,
        'notes' => $validated['notes'] ?? null,
      ]);

      return $this->dietIngredientsResponse($diet);
    }

    /**
     * @url PATCH /diets/{diet}/ingredients/{ingredientId}
     */
    public function updateDietIngredient(
      UpdateDietIngredientRequest $request,
      Diet                        $diet,
      int                         $ingredientId
    ): AnonymousResourceCollection
    {
      $diet->ingredients()
        ->whereKey($ingredientId)
        ->firstOrFail();

      $diet->ingredients()->updateExistingPivot(
        $ingredientId,
        $request->validated()
      );

      return $this->dietIngredientsResponse($diet);
    }

    /**
     * @url DELETE /diets/{diet}/ingredients/{ingredientId}
     */
    public function deleteDietIngredient(
      Diet $diet,
      int  $ingredientId
    ): AnonymousResourceCollection
    {
      $diet->ingredients()
        ->whereKey($ingredientId)
        ->firstOrFail();

      $diet->ingredients()->detach($ingredientId);

      return $this->dietIngredientsResponse($diet);
    }
  }
