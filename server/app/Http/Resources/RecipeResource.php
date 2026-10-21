<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class RecipeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'image' => $this->image,
            'image_url' => $this->image ? Storage::disk('recipe_images')->url($this->image) : null,
            'url' => $this->url,
            'author_id' => $this->author_id,
            'author' => new AuthorResource($this->whenLoaded('author')),
            'consumption_days' => $this->consumption_days,
            'daily_structures' => DailyStructureResource::collection($this->whenLoaded('dailyStructures')),
            'creator' => new UserResource($this->whenLoaded('creator')),
            'tags' => FoodTagResource::collection($this->whenLoaded('tags')),
            'ingredients' => RecipeIngredientResource::collection($this->whenLoaded('recipeIngredients')),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
