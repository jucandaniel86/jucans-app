<?php

  namespace App\Http\Controllers\Api;

  use App\Http\Controllers\Controller;
  use App\Http\Requests\Diet\DietDraftRequest;
  use App\Http\Resources\DailyStructureResource;
  use App\Http\Resources\DietResource;
  use App\Models\DailyStructure;
  use App\Models\Diet;
  use App\Services\DietService;
  use Illuminate\Support\Collection;

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
        'sources',
      ]);
    }
  }
