<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_progress_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('title');
            $table->text('content');
            $table->date('noted_at');
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps(6);
            $table->softDeletes('deleted_at', 6);
            $table->unique(['project_id', 'id'], 'progress_notes_project_identity_unique');
            $table->index(['project_id', 'deleted_at', 'noted_at'], 'progress_notes_journal_index');
        });
        Schema::create('project_progress_note_tasks', function (Blueprint $table) {
            $table->unsignedBigInteger('project_id');
            $table->unsignedBigInteger('note_id');
            $table->unsignedBigInteger('task_id');
            $table->primary(['note_id', 'task_id']);
            $table->unique(['project_id', 'note_id', 'task_id'], 'progress_note_tasks_identity_unique');
            $table->foreign(['project_id', 'note_id'], 'progress_note_tasks_note_foreign')
                ->references(['project_id', 'id'])->on('project_progress_notes')->restrictOnDelete();
            $table->foreign(['project_id', 'task_id'], 'progress_note_tasks_task_foreign')
                ->references(['project_id', 'id'])->on('project_tasks')->restrictOnDelete();
            $table->index(['task_id', 'note_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_progress_note_tasks');
        Schema::dropIfExists('project_progress_notes');
    }
};
