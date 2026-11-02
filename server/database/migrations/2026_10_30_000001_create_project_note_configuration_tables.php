<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_custom_fields', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('key', 100);
            $table->text('description')->nullable();
            $table->enum('type', ['number', 'select', 'boolean', 'text']);
            $table->string('unit', 100)->nullable();
            $table->decimal('min', 20, 6)->nullable();
            $table->decimal('max', 20, 6)->nullable();
            $table->decimal('step', 20, 6)->nullable();
            $table->enum('aggregation', ['average', 'sum', 'min', 'max', 'latest'])->nullable();
            // Phase 2 must set this atomically on the first value write. Never clear it.
            $table->timestamp('first_value_at', 6)->nullable();
            $table->timestamp('archived_at', 6)->nullable();
            $table->timestamps(6);
            $table->unique(['project_id', 'key']);
            $table->unique(['project_id', 'id'], 'custom_fields_project_identity');
        });
        Schema::create('project_custom_field_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('field_id')->constrained('project_custom_fields')->restrictOnDelete();
            $table->string('label');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamp('archived_at', 6)->nullable();
            $table->timestamps(6);
            $table->index(['field_id', 'sort_order']);
        });
        Schema::create('project_note_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->text('template')->nullable();
            // NULL means non-default; the unique slot permits only one default per project.
            $table->enum('is_default', ['1'])->nullable();
            $table->timestamp('archived_at', 6)->nullable();
            $table->timestamps(6);
            $table->unique(['project_id', 'name']);
            $table->unique(['project_id', 'is_default'], 'note_types_default_unique');
            $table->unique(['project_id', 'id'], 'note_types_project_identity');
        });
        Schema::create('project_note_type_fields', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('project_id');
            $table->unsignedBigInteger('note_type_id');
            $table->unsignedBigInteger('field_id');
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('required')->default(false);
            $table->timestamp('activated_at', 6);
            $table->timestamp('deactivated_at', 6)->nullable();
            $table->enum('active_slot', ['1'])->nullable()->default('1');
            $table->unique(['note_type_id', 'field_id', 'active_slot'], 'note_type_fields_active_unique');
            $table->foreign(['project_id', 'note_type_id'], 'note_type_fields_type_fk')
                ->references(['project_id', 'id'])->on('project_note_types')->restrictOnDelete();
            $table->foreign(['project_id', 'field_id'], 'note_type_fields_field_fk')
                ->references(['project_id', 'id'])->on('project_custom_fields')->restrictOnDelete();
            $table->index(['note_type_id', 'activated_at'], 'note_type_fields_history_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_note_type_fields');
        Schema::dropIfExists('project_note_types');
        Schema::dropIfExists('project_custom_field_options');
        Schema::dropIfExists('project_custom_fields');
    }
};
