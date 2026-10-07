<?php

use App\Enums\DietSourceType;
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
        Schema::create('diet_sources', function (Blueprint $table) {
          $table->id();
          $table->foreignId('diet_id')
            ->constrained('diets')
            ->cascadeOnDelete();
          $table->enum(
            'type',
            DietSourceType::values()
          );
          $table->boolean('is_official')->default(false);
          $table->string('title');
          $table->text('url');
          $table->text('notes')->nullable();
          $table->timestamps();
          $table->index([
            'diet_id',
            'type',
          ]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('diet_sources');
    }
};
