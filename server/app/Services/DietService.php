<?php

	namespace App\Services;

	use App\Enums\DietStatus;
	use App\Http\Resources\DailyStructureResource;
	use App\Models\DailyStructure;
	use App\Models\Diet;
	use App\Models\User;

	class DietService
	{

		public function createDraft(User $user, string $name): Diet
		{
			return Diet::create([
				'name' => $name,
				'status' => DietStatus::DRAFT,
				'created_by' => $user->id,
			]);
		}

		public function normalizeDailyStructurePositions(Diet $diet): void
		{
			$items = $diet->dailyStructure()
				->orderBy('position')
				->orderBy('id')
				->get();

			foreach ($items as $index => $item) {
				$position = $index + 1;

				if ($item->position !== $position) {
					$item->update([
						'position' => $position,
					]);
				}
			}
		}
	}