<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_task_deletion_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained('project_tasks')->restrictOnDelete();
            $table->timestamp('deleted_at', 6);
            $table->timestamp('restored_at', 6)->nullable();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('restored_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->index(['task_id', 'deleted_at', 'restored_at'], 'task_deletion_periods_interval_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_task_deletion_periods');
    }
};
