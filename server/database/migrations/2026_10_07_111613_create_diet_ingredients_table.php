<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
  use App\Enums\DietIngredientStatus;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('diet_ingredients', function (Blueprint $table) {
          $table->id();
          $table->foreignId('diet_id')
            ->constrained('diets')
            ->cascadeOnDelete();
          $table->foreignId('ingredient_id')
            ->constrained('ingredients')
            ->cascadeOnDelete();
          $table->enum(
            'status',
            DietIngredientStatus::values()
          );
          $table->text('notes')->nullable();
          $table->timestamps();
          $table->unique([
            'diet_id',
            'ingredient_id',
          ]);
          $table->index([
            'diet_id',
            'status',
          ]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('diet_ingredients');
    }
};
