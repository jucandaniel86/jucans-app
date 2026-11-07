<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Production SHOW CREATE TABLE verified: both tables use InnoDB, matching
        // BIGINT UNSIGNED project/id columns and UNIQUE(project_id,id) on fields.
        Schema::table('project_charts', function (Blueprint $table) {
            $table->enum('pie_aggregation', ['count', 'sum'])->nullable();
            $table->unsignedBigInteger('pie_value_field_id')->nullable();
            $table->index(['project_id', 'pie_value_field_id'], 'charts_pie_value_field_index');
            $table->foreign(['project_id', 'pie_value_field_id'], 'charts_pie_value_field_fk')
                ->references(['project_id', 'id'])->on('project_custom_fields')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('project_charts', function (Blueprint $table) {
            $table->dropForeign('charts_pie_value_field_fk');
            $table->dropIndex('charts_pie_value_field_index');
            $table->dropColumn(['pie_aggregation', 'pie_value_field_id']);
        });
    }
};
