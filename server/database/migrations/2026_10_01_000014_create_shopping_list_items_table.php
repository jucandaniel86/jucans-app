<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shopping_list_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shopping_list_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ingredient_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->decimal('calculated_quantity', 12, 3)->nullable();
            $table->decimal('quantity', 12, 3)->nullable();
            $table->string('unit')->nullable();
            $table->foreignId('shopping_category_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('is_checked')->default(false);
            $table->boolean('quantity_overridden')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shopping_list_items');
    }
};
