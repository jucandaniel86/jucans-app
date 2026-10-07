<?php

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
        Schema::create('recipe_daily_structure', function (Blueprint $table) {
          $table->id();
          $table->foreignId('recipe_id')
            ->constrained('recipes')
            ->cascadeOnDelete();
          $table->foreignId('daily_structure_id')
            ->constrained('daily_structures');
          $table->timestamps();
          $table->unique([
            'recipe_id',
            'daily_structure_id',
          ]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('recipe_daily_structure');
    }
};
