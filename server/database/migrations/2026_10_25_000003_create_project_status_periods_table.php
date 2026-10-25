<?php

use App\Enums\ProjectStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_status_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->restrictOnDelete();
            $table->enum('status', array_column(ProjectStatus::cases(), 'value'));
            $table->timestamp('starts_at', 6);
            $table->timestamp('ends_at', 6)->nullable();
            $table->foreignId('changed_by')->constrained('users')->restrictOnDelete();
            $table->timestamps(6);

            $table->index(['project_id', 'starts_at']);
            $table->index(['project_id', 'ends_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_status_periods');
    }
};
