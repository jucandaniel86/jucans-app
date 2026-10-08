<?php

	namespace App\Models;

	use Illuminate\Database\Eloquent\Factories\HasFactory;
	use Illuminate\Database\Eloquent\Model;
	use Illuminate\Database\Eloquent\Relations\BelongsTo;

	class DietDailyStructure extends Model
	{
		use HasFactory;

		protected $table = 'diet_daily_structure';

		protected $fillable = [
			'diet_id',
			'daily_structure_id',
			'position',
		];

		public function structure(): BelongsTo
		{
			return $this->belongsTo(
				DailyStructure::class,
				'daily_structure_id'
			);
		}
	}