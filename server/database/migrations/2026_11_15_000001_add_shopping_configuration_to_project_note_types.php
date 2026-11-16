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
            // SQLite cannot add composite foreign keys to an existing table.
            DB::statement("ALTER TABLE project_note_types ADD COLUMN is_shopping VARCHAR(1) NULL CHECK (is_shopping IS NULL OR is_shopping = '1')");
            DB::statement('ALTER TABLE project_note_types ADD COLUMN shopping_amount_field_id INTEGER NULL REFERENCES project_custom_fields(id) ON DELETE RESTRICT');
            DB::statement('ALTER TABLE project_note_types ADD COLUMN shopping_provider_field_id INTEGER NULL REFERENCES project_custom_fields(id) ON DELETE RESTRICT');
            DB::statement('ALTER TABLE project_note_types ADD COLUMN shopping_provider_option_id INTEGER NULL REFERENCES project_custom_field_options(id) ON DELETE RESTRICT');
            $invalid = '(NEW.shopping_amount_field_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM project_custom_fields WHERE id = NEW.shopping_amount_field_id AND project_id = NEW.project_id)) OR (NEW.shopping_provider_field_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM project_custom_fields WHERE id = NEW.shopping_provider_field_id AND project_id = NEW.project_id)) OR (NEW.shopping_provider_field_id IS NOT NULL AND NEW.shopping_provider_option_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM project_custom_field_options WHERE id = NEW.shopping_provider_option_id AND field_id = NEW.shopping_provider_field_id))';
            foreach (['INSERT', 'UPDATE'] as $event) {
                DB::statement("CREATE TRIGGER shopping_type_scope_{$event} BEFORE {$event} ON project_note_types WHEN {$invalid} BEGIN SELECT RAISE(ABORT, 'Invalid Shopping configuration scope'); END");
            }
            Schema::table('project_note_types', fn (Blueprint $table) => $table->unique(['project_id', 'is_shopping'], 'note_types_shopping_unique'));
            return;
        }
        Schema::table('project_note_types', function (Blueprint $table) {
            // Same nullable unique-slot pattern as is_default; false is stored as NULL.
            $table->enum('is_shopping', ['1'])->nullable();
            $table->unsignedBigInteger('shopping_amount_field_id')->nullable();
            $table->unsignedBigInteger('shopping_provider_field_id')->nullable();
            $table->unsignedBigInteger('shopping_provider_option_id')->nullable();
            $table->unique(['project_id', 'is_shopping'], 'note_types_shopping_unique');
            $table->index(['project_id', 'shopping_amount_field_id'], 'shopping_amount_field_index');
            $table->index(['project_id', 'shopping_provider_field_id'], 'shopping_provider_field_index');
            $table->index(['shopping_provider_field_id', 'shopping_provider_option_id'], 'shopping_provider_option_index');
            $table->foreign(['project_id', 'shopping_amount_field_id'], 'shopping_amount_field_fk')->references(['project_id', 'id'])->on('project_custom_fields')->restrictOnDelete();
            $table->foreign(['project_id', 'shopping_provider_field_id'], 'shopping_provider_field_fk')->references(['project_id', 'id'])->on('project_custom_fields')->restrictOnDelete();
            $table->foreign(['shopping_provider_field_id', 'shopping_provider_option_id'], 'shopping_provider_option_fk')->references(['field_id', 'id'])->on('project_custom_field_options')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('DROP TRIGGER IF EXISTS shopping_type_scope_INSERT');
            DB::statement('DROP TRIGGER IF EXISTS shopping_type_scope_UPDATE');
        }
        Schema::table('project_note_types', function (Blueprint $table) {
            if (DB::getDriverName() !== 'sqlite') {
                $table->dropForeign('shopping_amount_field_fk');
                $table->dropForeign('shopping_provider_field_fk');
                $table->dropForeign('shopping_provider_option_fk');
                $table->dropIndex('shopping_amount_field_index');
                $table->dropIndex('shopping_provider_field_index');
                $table->dropIndex('shopping_provider_option_index');
            }
            $table->dropUnique('note_types_shopping_unique');
            $table->dropColumn(['is_shopping', 'shopping_amount_field_id', 'shopping_provider_field_id', 'shopping_provider_option_id']);
        });
    }
};
