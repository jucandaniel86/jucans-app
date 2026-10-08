<?php

  namespace App\Http\Resources;

  use Illuminate\Http\Request;
  use Illuminate\Http\Resources\Json\JsonResource;

  class DietIngredientResource extends JsonResource
  {
    public function toArray(Request $request): array
    {
      return [
        'id' => $this->id,
        'name' => $this->name,
        'default_unit' => $this->default_unit,
        'status' => $this->pivot->status,
        'notes' => $this->pivot->notes,
      ];
    }
  }
