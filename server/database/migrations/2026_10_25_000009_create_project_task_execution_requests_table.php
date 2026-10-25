<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Receipts also retain no-op requests, without manufacturing audit events.
        Schema::create('project_task_execution_requests', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('task_id');
            $table->unsignedBigInteger('occurrence_id');
            $table->string('request_id', 100);
            $table->char('payload_hash', 64);
            $table->timestamp('recorded_at', 6);
            $table->unique(['task_id', 'request_id'], 'task_execution_requests_unique');
            $table->foreign(['task_id', 'occurrence_id'], 'task_execution_requests_occurrence_foreign')
                ->references(['task_id', 'id'])->on('project_task_occurrences')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_task_execution_requests');
    }
};
