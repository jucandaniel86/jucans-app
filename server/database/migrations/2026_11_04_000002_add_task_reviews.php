<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_tasks', function (Blueprint $table) {
            $table->enum('review_mode', ['single', 'multiple'])->default('single');
        });
        Schema::create('project_task_reviews', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->id();
            $table->unsignedBigInteger('project_id');
            $table->unsignedBigInteger('task_id');
            $table->unsignedBigInteger('occurrence_id');
            $table->unsignedBigInteger('note_id')->unique();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            // Single = 0; Multiple = author ID. Slots survive note soft deletion.
            $table->unsignedBigInteger('slot');
            $table->unique(['occurrence_id', 'slot'], 'task_reviews_slot_unique');
            $table->unique(['occurrence_id', 'user_id'], 'task_reviews_author_unique');
            $table->foreign(['project_id', 'task_id'], 'task_reviews_project_task_fk')
                ->references(['project_id', 'id'])->on('project_tasks')->restrictOnDelete();
            $table->foreign(['task_id', 'occurrence_id'], 'task_reviews_occurrence_fk')
                ->references(['task_id', 'id'])->on('project_task_occurrences')->restrictOnDelete();
            $table->foreign(['project_id', 'note_id'], 'task_reviews_note_fk')
                ->references(['project_id', 'id'])->on('project_progress_notes')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_task_reviews');
        Schema::table('project_tasks', fn (Blueprint $table) => $table->dropColumn('review_mode'));
    }
};
