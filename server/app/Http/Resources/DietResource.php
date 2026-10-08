<?php

	namespace App\Http\Resources;

	use Illuminate\Http\Request;
	use Illuminate\Http\Resources\Json\JsonResource;

	/**
	 * @mixin \App\Models\Diet
	 */
	class DietResource extends JsonResource
	{
		/**
		 * @return array<string, mixed>
		 */

		public function toArray(Request $request): array
		{
			return [
				'id' => $this->id,
				'name' => $this->name,
				'description' => $this->description,
				'notes' => $this->notes,
				'thumbnail' => $this->thumbnail,
				'status' => $this->status->value,
				'creator' => new UserResource(
					$this->whenLoaded('creator')
				),
				'sources' => DietSourceResource::collection(
					$this->whenLoaded('sources')
				),
				// TODO
				// 'ingredients' => DietIngredientResource::collection(
				//     $this->whenLoaded('ingredients')
				// ),
				//
				// 'recipes' => DietRecipeResource::collection(
				//     $this->whenLoaded('recipes')
				// ),
				//
				'daily_structure' => DietDailyStructureResource::collection(
					$this->whenLoaded('dailyStructure')
				),
				'created_at' => $this->created_at?->toISOString(),
				'updated_at' => $this->updated_at?->toISOString(),
			];
		}
	}