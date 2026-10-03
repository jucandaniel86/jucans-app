<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shopping_list_recipes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shopping_list_id')->constrained()->cascadeOnDelete();
            $table->foreignId('recipe_id')->constrained()->cascadeOnDelete();
            $table->foreignId('added_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['shopping_list_id', 'recipe_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shopping_list_recipes');
    }
};
