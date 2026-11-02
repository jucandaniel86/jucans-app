<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::getDriverName();
        if ($driver === 'mysql') {
            $version = DB::selectOne('SELECT VERSION() AS version')->version;
            preg_match('/\d+\.\d+\.\d+/', $version, $parts);
            if (str_contains(strtolower($version), 'mariadb') || ! isset($parts[0]) || version_compare($parts[0], '8.0.16', '<')) {
                throw new RuntimeException('Note values require MySQL >= 8.0.16 with enforced CHECK constraints (MariaDB is not verified).');
            }
            $tables = DB::select("SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('project_progress_notes','project_note_types','project_custom_fields','project_custom_field_options')");
            if (count($tables) !== 4 || collect($tables)->contains(fn ($table) => strcasecmp($table->ENGINE, 'InnoDB') !== 0) || ! DB::selectOne('SELECT @@foreign_key_checks AS enabled')->enabled) {
                throw new RuntimeException('Note values require existing InnoDB configuration/note tables and enabled foreign key checks.');
            }
        } elseif ($driver !== 'sqlite' || version_compare(DB::selectOne('SELECT sqlite_version() AS version')->version, '3.35.0', '<')) {
            throw new RuntimeException('Note values support MySQL >= 8.0.16 or SQLite >= 3.35.0 only.');
        }
        if ($driver === 'sqlite' && ! DB::selectOne('PRAGMA foreign_keys')->foreign_keys) {
            throw new RuntimeException('SQLite foreign key enforcement must be enabled.');
        }
        Schema::table('project_custom_field_options', function (Blueprint $table) {
            $table->unique(['field_id', 'id'], 'field_options_identity');
        });
        // Laravel 10 cannot add a composite FK to an existing SQLite table.
        // Keep the identity FK native and enforce its project scope with triggers below.
        if ($driver === 'sqlite') {
            DB::statement('ALTER TABLE project_progress_notes ADD COLUMN note_type_id INTEGER NULL REFERENCES project_note_types(id) ON DELETE RESTRICT');
        } else {
            Schema::table('project_progress_notes', function (Blueprint $table) {
                $table->unsignedBigInteger('note_type_id')->nullable();
                $table->foreign(['project_id', 'note_type_id'], 'note_configuration_type_fk')
                    ->references(['project_id', 'id'])->on('project_note_types')->restrictOnDelete();
            });
        }
        Schema::table('project_progress_notes', function (Blueprint $table) {
            $table->json('configuration_snapshot')->nullable();
            $table->timestamp('configuration_captured_at', 6)->nullable();
            $table->index(['project_id', 'note_type_id'], 'notes_type_index');
        });
        Schema::create('project_progress_note_values', function (Blueprint $table) use ($driver) {
            if ($driver === 'mysql') {
                $table->engine = 'InnoDB';
            }
            $table->id();
            $table->unsignedBigInteger('project_id');
            $table->unsignedBigInteger('note_id');
            $table->unsignedBigInteger('field_id');
            $table->enum('value_type', ['number', 'text', 'boolean', 'select']);
            // SQLite NUMERIC affinity would round exact decimal strings to binary floats.
            if ($driver === 'sqlite') {
                $table->string('number_value', 22)->nullable();
            } else {
                $table->decimal('number_value', 20, 6)->nullable();
            }
            $table->longText('text_value')->nullable();
            $table->boolean('boolean_value')->nullable();
            $table->unsignedBigInteger('select_option_id')->nullable();
            $table->timestamps(6);
            $table->unique(['note_id', 'field_id'], 'note_values_identity');
            $table->foreign(['project_id', 'note_id'], 'note_values_note_fk')->references(['project_id', 'id'])->on('project_progress_notes')->restrictOnDelete();
            $table->foreign(['project_id', 'field_id'], 'note_values_field_fk')->references(['project_id', 'id'])->on('project_custom_fields')->restrictOnDelete();
            $table->foreign(['field_id', 'select_option_id'], 'note_values_option_fk')->references(['field_id', 'id'])->on('project_custom_field_options')->restrictOnDelete();
            $table->index(['project_id', 'field_id'], 'note_values_field_index');
        });
        $context = '((note_type_id IS NULL AND configuration_snapshot IS NULL AND configuration_captured_at IS NULL) OR (note_type_id IS NOT NULL AND configuration_snapshot IS NOT NULL AND configuration_captured_at IS NOT NULL))';
        $typed = "value_type IN ('number','text','boolean','select') AND (boolean_value IS NULL OR boolean_value IN (0,1)) AND (number_value IS NULL OR value_type = 'number') AND (text_value IS NULL OR value_type = 'text') AND (boolean_value IS NULL OR value_type = 'boolean') AND (select_option_id IS NULL OR value_type = 'select')";
        if ($driver === 'sqlite') {
            foreach (['INSERT', 'UPDATE'] as $operation) {
                $suffix = strtolower($operation);
                $newContext = preg_replace('/\b(note_type_id|configuration_snapshot|configuration_captured_at)\b/', 'NEW.$1', $context);
                $frozen = $operation === 'UPDATE' ? ' OR (OLD.note_type_id IS NOT NULL AND (NEW.note_type_id IS NOT OLD.note_type_id OR NEW.configuration_snapshot IS NOT OLD.configuration_snapshot OR NEW.configuration_captured_at IS NOT OLD.configuration_captured_at))' : '';
                DB::statement("CREATE TRIGGER note_context_{$suffix} BEFORE {$operation} ON project_progress_notes WHEN NOT {$newContext}{$frozen} OR (NEW.configuration_snapshot IS NOT NULL AND NOT json_valid(NEW.configuration_snapshot)) OR (NEW.note_type_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM project_note_types WHERE id = NEW.note_type_id AND project_id = NEW.project_id)) BEGIN SELECT RAISE(ABORT, 'Invalid note configuration context'); END");
                $newTyped = preg_replace('/\b(value_type|number_value|text_value|boolean_value|select_option_id)\b/', 'NEW.$1', $typed);
                DB::statement("CREATE TRIGGER note_values_type_{$suffix} BEFORE {$operation} ON project_progress_note_values WHEN NOT ({$newTyped}) BEGIN SELECT RAISE(ABORT, 'Invalid typed note value'); END");
            }
            DB::statement("CREATE TRIGGER note_type_project_guard BEFORE UPDATE OF project_id ON project_note_types WHEN EXISTS (SELECT 1 FROM project_progress_notes WHERE note_type_id = OLD.id AND project_id != NEW.project_id) BEGIN SELECT RAISE(ABORT, 'Note Type project is referenced by notes'); END");
        } else {
            DB::statement("ALTER TABLE project_progress_notes ADD CONSTRAINT note_context_check CHECK {$context}");
            DB::statement("ALTER TABLE project_progress_note_values ADD CONSTRAINT note_value_type_check CHECK ({$typed})");
            DB::unprepared("CREATE TRIGGER note_context_immutable BEFORE UPDATE ON project_progress_notes FOR EACH ROW BEGIN IF OLD.note_type_id IS NOT NULL AND (NOT (NEW.note_type_id <=> OLD.note_type_id) OR NOT (NEW.configuration_snapshot <=> OLD.configuration_snapshot) OR NOT (NEW.configuration_captured_at <=> OLD.configuration_captured_at)) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Saved note configuration is immutable'; END IF; END");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('project_progress_note_values');
        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared('DROP TRIGGER IF EXISTS note_context_insert');
            DB::unprepared('DROP TRIGGER IF EXISTS note_context_update');
            DB::unprepared('DROP TRIGGER IF EXISTS note_type_project_guard');
        } else {
            DB::unprepared('DROP TRIGGER IF EXISTS note_context_immutable');
            DB::statement('ALTER TABLE project_progress_notes DROP CHECK note_context_check');
            Schema::table('project_progress_notes', fn (Blueprint $table) => $table->dropForeign('note_configuration_type_fk'));
        }
        Schema::table('project_progress_notes', function (Blueprint $table) {
            $table->dropIndex('notes_type_index');
            $table->dropColumn(['note_type_id', 'configuration_snapshot', 'configuration_captured_at']);
        });
        Schema::table('project_custom_field_options', fn (Blueprint $table) => $table->dropUnique('field_options_identity'));
    }
};
