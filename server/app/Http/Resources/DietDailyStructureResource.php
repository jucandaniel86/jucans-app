<?php

	namespace App\Http\Resources;

	use App\Models\DailyStructure;
	use Illuminate\Http\Request;
	use Illuminate\Http\Resources\Json\JsonResource;

	class DietDailyStructureResource extends JsonResource
	{
		/**
		 * Transform the resource into an array.
		 *
		 * @return array<string, mixed>
		 */
		public function toArray(Request $request): array
		{
			return [
				'id' => $this->id,
				'position' => $this->position,
				'diet_id' => $this->diet_id,
				'structure' => new DailyStructureResource(
					$this->whenLoaded('structure')
				),
			];
		}
	}