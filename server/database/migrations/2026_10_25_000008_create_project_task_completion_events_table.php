<?php

use App\Enums\ProjectTaskCompletionAction;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_task_completion_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('occurrence_id')->constrained('project_task_occurrences')->restrictOnDelete();
            $table->enum('action', array_column(ProjectTaskCompletionAction::cases(), 'value'));
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->date('performed_on');
            $table->timestamp('recorded_at', 6);
            $table->unsignedBigInteger('reverses_event_id')->nullable();
            $table->string('request_id', 100);
            $table->unique(['occurrence_id', 'request_id'], 'task_completion_events_request_unique');
            $table->unique(['occurrence_id', 'id'], 'task_completion_events_identity_unique');
            $table->unique('reverses_event_id');
            $table->foreign(['occurrence_id', 'reverses_event_id'], 'task_completion_events_reversal_foreign')
                ->references(['occurrence_id', 'id'])->on('project_task_completion_events')->restrictOnDelete();
            $table->index(['occurrence_id', 'performed_on', 'id'], 'task_completion_events_business_date_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_task_completion_events');
    }
};
