<?php

use App\Enums\ProjectRecurrenceType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_task_schedule_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained('project_tasks')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->date('effective_from');
            $table->date('effective_until')->nullable();
            $table->enum('recurrence_type', array_column(ProjectRecurrenceType::cases(), 'value'));
            $table->unsignedInteger('interval_days')->nullable();
            $table->json('weekdays')->nullable();
            $table->unsignedTinyInteger('month_day')->nullable();
            $table->date('anchor_date');
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->time('scheduled_time')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps(6);
            $table->unique(['task_id', 'version'], 'task_schedule_versions_number_unique');
            $table->unique(['task_id', 'id'], 'task_schedule_versions_identity_unique');
            $table->index(['task_id', 'effective_from', 'effective_until'], 'task_schedule_versions_effective_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_task_schedule_versions');
    }
};
