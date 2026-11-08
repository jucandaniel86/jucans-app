<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_task_reviews', function (Blueprint $table) {
            // NULL releases both uniqueness reservations without deleting audit links.
            // Multiple reviews keep '1' even when their notes are soft-deleted.
            $table->enum('active_slot', ['1'])->nullable()->default('1');
        });
        Schema::table('project_task_reviews', function (Blueprint $table) {
            $table->dropUnique('task_reviews_slot_unique');
            $table->dropUnique('task_reviews_author_unique');
            $table->unique(['occurrence_id', 'slot', 'active_slot'], 'task_reviews_slot_unique');
            $table->unique(['occurrence_id', 'user_id', 'active_slot'], 'task_reviews_author_unique');
        });
        DB::table('project_task_reviews')
            ->whereIn('task_id', DB::table('project_tasks')->select('id')->where('review_mode', 'single'))
            ->whereIn('note_id', DB::table('project_progress_notes')->select('id')->whereNotNull('deleted_at'))
            ->update(['active_slot' => null]);
    }

    public function down(): void
    {
        // Recreating Single reviews may produce history that the old schema cannot
        // represent. Refuse rollback instead of deleting audit records.
        foreach (['slot', 'user_id'] as $column) {
            if (DB::table('project_task_reviews')->select(['occurrence_id', $column])
                ->groupBy('occurrence_id', $column)->havingRaw('COUNT(*) > 1')->exists()) {
                throw new RuntimeException('Cannot restore old review uniqueness without losing history. Rollback stopped.');
            }
        }
        Schema::table('project_task_reviews', function (Blueprint $table) {
            $table->dropUnique('task_reviews_slot_unique');
            $table->dropUnique('task_reviews_author_unique');
            $table->unique(['occurrence_id', 'slot'], 'task_reviews_slot_unique');
            $table->unique(['occurrence_id', 'user_id'], 'task_reviews_author_unique');
        });
        Schema::table('project_task_reviews', fn (Blueprint $table) => $table->dropColumn('active_slot'));
    }
};
