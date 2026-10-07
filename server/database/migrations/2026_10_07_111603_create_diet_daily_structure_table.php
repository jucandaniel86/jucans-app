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
        Schema::create('diet_daily_structure', function (Blueprint $table) {
          $table->id();
          $table->foreignId('diet_id')
            ->constrained('diets')
            ->cascadeOnDelete();
          $table->foreignId('daily_structure_id')
            ->constrained('daily_structures');
          $table->unsignedSmallInteger('position');
          $table->timestamps();
          $table->index(['diet_id', 'position']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('diet_daily_structure');
    }
};
