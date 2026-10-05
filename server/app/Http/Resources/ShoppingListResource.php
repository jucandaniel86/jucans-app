<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ShoppingListResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'status' => $this->status,
            'visibility' => $this->visibility,
            'created_by' => $this->created_by,
            'creator' => new ShoppingListUserResource($this->whenLoaded('creator')),
            'is_creator' => (int) $this->created_by === (int) $request->user()->id,
            'is_shared_with_me' => $this->visibility === 'shared'
                && (int) $this->created_by !== (int) $request->user()->id
                && (bool) $this->shared_with_authenticated_user,
            'closed_at' => $this->closed_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'recipes_count' => $this->whenCounted('recipes'),
            'items_count' => $this->whenCounted('items'),
            'unchecked_items_count' => $this->whenCounted('uncheckedItems'),
            'items' => ShoppingListItemResource::collection($this->whenLoaded('items')),
            'recipes' => RecipeResource::collection($this->whenLoaded('recipes')),
        ];
    }
}
