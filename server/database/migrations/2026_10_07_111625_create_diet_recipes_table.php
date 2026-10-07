<?php

use App\Enums\DietRecipePriority;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('diet_recipes', function (Blueprint $table) {
          $table->id();
          $table->foreignId('diet_id')
            ->constrained('diets')
            ->cascadeOnDelete();
          $table->foreignId('recipe_id')
            ->constrained('recipes')
            ->cascadeOnDelete();
          $table->enum(
            'priority',
            DietRecipePriority::values()
          )->default(DietRecipePriority::NORMAL->value);
          $table->text('notes')->nullable();
          $table->timestamps();
          $table->unique([
            'diet_id',
            'recipe_id',
          ]);
          $table->index([
            'diet_id',
            'priority',
          ]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('diet_recipes');
    }
};
