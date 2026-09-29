<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('food_tag_recipe', function (Blueprint $table) {
            $table->foreignId('food_tag_id')->constrained()->cascadeOnDelete();
            $table->foreignId('recipe_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->primary(['food_tag_id', 'recipe_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('food_tag_recipe');
    }
};
