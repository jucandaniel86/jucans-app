<?php

use App\Enums\ProjectStatus;
use App\Enums\ProjectTaskExecutionType;
use App\Enums\ProjectTaskPriority;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->enum('execution_type', array_column(ProjectTaskExecutionType::cases(), 'value'));
            $table->enum('priority', array_column(ProjectTaskPriority::cases(), 'value'))->default('medium');
            $table->enum('status', array_column(ProjectStatus::cases(), 'value'))->default('active');
            $table->date('scheduled_date')->nullable();
            $table->time('scheduled_time')->nullable();
            $table->date('due_date')->nullable();
            $table->time('due_time')->nullable();
            $table->boolean('show_in_daily_widget')->default(true);
            $table->timestamps(6);
            $table->softDeletes('deleted_at', 6);
            $table->unique(['project_id', 'id'], 'project_tasks_project_identity_unique');
            $table->index(['project_id', 'deleted_at', 'status'], 'project_tasks_listing_index');
            $table->index(['project_id', 'created_by']);
            $table->index(['project_id', 'due_date']);
            $table->index(['project_id', 'scheduled_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_tasks');
    }
};
