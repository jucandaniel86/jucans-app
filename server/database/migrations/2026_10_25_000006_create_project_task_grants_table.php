<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_task_grants', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('project_id');
            $table->unsignedBigInteger('collaborator_id');
            $table->unsignedBigInteger('task_id');
            $table->timestamps();
            $table->unique(['collaborator_id', 'task_id']);
            $table->foreign(['project_id', 'collaborator_id'], 'project_task_grants_collaborator_foreign')
                ->references(['project_id', 'id'])->on('project_collaborators')->cascadeOnDelete();
            $table->foreign(['project_id', 'task_id'], 'project_task_grants_task_foreign')
                ->references(['project_id', 'id'])->on('project_tasks')->restrictOnDelete();
            $table->index(['task_id', 'collaborator_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_task_grants');
    }
};
