<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_task_occurrences', function (Blueprint $table) {
            $table->unsignedBigInteger('schedule_version_id')->nullable();
            $table->foreign(['task_id', 'schedule_version_id'], 'task_occurrences_schedule_foreign')
                ->references(['task_id', 'id'])->on('project_task_schedule_versions')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('project_task_occurrences', function (Blueprint $table) {
            $table->dropForeign('task_occurrences_schedule_foreign');
            $table->dropColumn('schedule_version_id');
        });
    }
};
