<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_charts', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->restrictOnDelete();
            $table->string('title');
            $table->enum('type', ['pie', 'line', 'bar']);
            $table->integer('priority')->default(0);
            $table->boolean('active')->default(true);
            foreach (['total', 'y_min', 'y_max'] as $column) {
                if (DB::getDriverName() === 'sqlite') {
                    $table->string($column, 22)->nullable();
                } else {
                    $table->decimal($column, 20, 6)->nullable();
                }
            }
            $table->timestamps(6);
            $table->unique(['project_id', 'id'], 'charts_project_identity');
            $table->index(['project_id', 'active', 'priority', 'id'], 'charts_display_order');
        });
        Schema::create('project_chart_fields', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->id();
            $table->unsignedBigInteger('project_id');
            $table->unsignedBigInteger('chart_id');
            $table->unsignedBigInteger('field_id');
            $table->unsignedInteger('position')->default(0);
            $table->unique(['chart_id', 'field_id'], 'chart_fields_identity');
            $table->foreign(['project_id', 'chart_id'], 'chart_fields_chart_fk')
                ->references(['project_id', 'id'])->on('project_charts')->cascadeOnDelete();
            $table->foreign(['project_id', 'field_id'], 'chart_fields_field_fk')
                ->references(['project_id', 'id'])->on('project_custom_fields')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_chart_fields');
        Schema::dropIfExists('project_charts');
    }
};
