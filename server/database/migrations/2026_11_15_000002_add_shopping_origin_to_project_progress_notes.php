<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('ALTER TABLE project_progress_notes ADD COLUMN shopping_list_id INTEGER NULL REFERENCES shopping_lists(id) ON DELETE RESTRICT');
            DB::statement('ALTER TABLE project_progress_notes ADD COLUMN shopping_request_hash VARCHAR(64) NULL');
            Schema::table('project_progress_notes', fn (Blueprint $table) => $table->unique(['shopping_list_id', 'created_by'], 'shopping_note_user_unique'));
            return;
        }
        Schema::table('project_progress_notes', function (Blueprint $table) {
            $table->unsignedBigInteger('shopping_list_id')->nullable();
            $table->char('shopping_request_hash', 64)->charset('ascii')->collation('ascii_bin')->nullable();
            // NULL origin leaves generic Notes unchanged. Soft deletion does not free this slot.
            $table->unique(['shopping_list_id', 'created_by'], 'shopping_note_user_unique');
            $table->foreign('shopping_list_id', 'shopping_note_list_fk')->references('id')->on('shopping_lists')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('project_progress_notes', function (Blueprint $table) {
            if (DB::getDriverName() !== 'sqlite') $table->dropForeign('shopping_note_list_fk');
            $table->dropUnique('shopping_note_user_unique');
            $table->dropColumn(['shopping_list_id', 'shopping_request_hash']);
        });
    }
};
