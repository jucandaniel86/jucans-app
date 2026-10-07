<?php

  use App\Enums\DietStatus;

  use Illuminate\Database\Migrations\Migration;

  use Illuminate\Database\Schema\Blueprint;

  use Illuminate\Support\Facades\Schema;

  return new class extends Migration
  {
    public function up(): void
    {
      Schema::create('diets', function (Blueprint $table) {
        $table->id();
        $table->string('name');
        $table->text('description')->nullable();
        $table->text('notes')->nullable();
        $table->string('thumbnail')->nullable();
        $table->enum('status', DietStatus::values())
          ->default(DietStatus::DRAFT->value);
        $table->foreignId('created_by')
          ->constrained('users');
        $table->timestamps();
        $table->index('status');
        $table->index('created_by');
      });
    }

    public function down(): void
    {
      Schema::dropIfExists('diets');
    }
  };
