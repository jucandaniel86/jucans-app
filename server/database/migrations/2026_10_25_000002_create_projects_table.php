<?php

use App\Enums\ProjectProgressType;
use App\Enums\ProjectStatus;
use App\Enums\ProjectTaskExecutionType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->foreignId('category_id')->constrained('project_categories')->restrictOnDelete();
            $table->string('icon')->default('folder');
            $table->enum('progress_type', array_column(ProjectProgressType::cases(), 'value'))->default('completion');
            $table->enum('default_execution_type', array_column(ProjectTaskExecutionType::cases(), 'value'))->default('one_time');
            $table->text('note_template')->nullable();
            $table->enum('status', array_column(ProjectStatus::cases(), 'value'))->default('active');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('last_activity_at', 6);
            $table->timestamps(6);

            $table->index(['created_by', 'status', 'category_id', 'last_activity_at'], 'projects_owner_status_activity_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
