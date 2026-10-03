<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('recipe_ingredients')
            ->select('id', 'ingredient_id', 'unit')
            ->orderBy('id')
            ->chunkById(500, function ($recipeIngredients): void {
                $defaultUnits = DB::table('ingredients')
                    ->whereIn('id', $recipeIngredients->pluck('ingredient_id')->unique())
                    ->pluck('default_unit', 'id');

                foreach ($recipeIngredients as $recipeIngredient) {
                    DB::table('recipe_ingredients')
                        ->where('id', $recipeIngredient->id)
                        ->update([
                            'needs_review' => $this->normalizeUnit($recipeIngredient->unit)
                                !== $this->normalizeUnit($defaultUnits[$recipeIngredient->ingredient_id] ?? null),
                        ]);
                }
            });
    }

    public function down(): void
    {
        //
    }

    private function normalizeUnit(?string $unit): ?string
    {
        return $unit === null || $unit === '' || $unit === 'none'
            ? null
            : $unit;
    }
};
