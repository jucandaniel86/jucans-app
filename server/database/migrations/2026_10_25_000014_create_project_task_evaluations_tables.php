<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_task_evaluations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('project_id');
            $table->unsignedBigInteger('task_id');
            $table->date('evaluation_date');
            $table->unsignedTinyInteger('score');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('note_id')->nullable();
            $table->unsignedBigInteger('lock_version')->default(0);
            // NULL releases a suspended/withdrawn score's slot; only '1' is active.
            $table->enum('active_slot', ['1'])->nullable()->default('1');
            $table->timestamp('withdrawn_at', 6)->nullable();
            $table->timestamps(6);
            $table->unique(['task_id', 'evaluation_date', 'active_slot'], 'task_evaluations_active_day_unique');
            $table->foreign(['project_id', 'task_id'], 'task_evaluations_task_foreign')
                ->references(['project_id', 'id'])->on('project_tasks')->restrictOnDelete();
            $table->foreign(['project_id', 'note_id', 'task_id'], 'task_evaluations_note_task_foreign')
                ->references(['project_id', 'note_id', 'task_id'])->on('project_progress_note_tasks')->restrictOnDelete();
            $table->index(['note_id', 'active_slot']);
        });
        Schema::create('project_task_evaluation_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('evaluation_id')->constrained('project_task_evaluations')->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->unsignedTinyInteger('previous_score')->nullable();
            $table->unsignedTinyInteger('new_score')->nullable();
            $table->foreignId('previous_note_id')->nullable()->constrained('project_progress_notes')->restrictOnDelete();
            $table->foreignId('new_note_id')->nullable()->constrained('project_progress_notes')->restrictOnDelete();
            $table->timestamp('recorded_at', 6);
            $table->index(['evaluation_id', 'id']);
        });
        // SQLite cannot add a CHECK through ALTER TABLE; equivalent triggers enforce it.
        if (DB::getDriverName() === 'sqlite') {
            foreach (['INSERT', 'UPDATE'] as $operation) {
                DB::unprepared("CREATE TRIGGER task_evaluation_score_{$operation} BEFORE {$operation} ON project_task_evaluations
                    WHEN NEW.score < 1 OR NEW.score > 10 BEGIN SELECT RAISE(ABORT, 'Score must be between 1 and 10'); END");
            }
        } else {
            DB::statement('ALTER TABLE project_task_evaluations ADD CONSTRAINT task_evaluation_score_check CHECK (score BETWEEN 1 AND 10)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('project_task_evaluation_revisions');
        Schema::dropIfExists('project_task_evaluations');
    }
};
