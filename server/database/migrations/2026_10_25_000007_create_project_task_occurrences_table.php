<?php

use App\Enums\ProjectTaskOccurrenceStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_task_occurrences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained('project_tasks')->restrictOnDelete();
            $table->string('occurrence_key', 10);
            $table->date('scheduled_date')->nullable();
            $table->time('scheduled_time')->nullable();
            $table->date('due_date')->nullable();
            $table->time('due_time')->nullable();
            $table->enum('status', array_column(ProjectTaskOccurrenceStatus::cases(), 'value'))->default('pending');
            $table->foreignId('completed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('completed_at', 6)->nullable();
            $table->unsignedBigInteger('lock_version')->default(0);
            $table->timestamps(6);
            $table->unique(['task_id', 'occurrence_key'], 'task_occurrences_logical_unique');
            $table->unique(['task_id', 'id'], 'task_occurrences_identity_unique');
            $table->index(['task_id', 'scheduled_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_task_occurrences');
    }
};
