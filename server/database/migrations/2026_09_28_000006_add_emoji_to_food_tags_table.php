<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('food_tags', function (Blueprint $table) {
            $table->string('emoji', 32)->nullable()->after('normalized_name');
        });
    }

    public function down(): void
    {
        Schema::table('food_tags', function (Blueprint $table) {
            $table->dropColumn('emoji');
        });
    }
};
