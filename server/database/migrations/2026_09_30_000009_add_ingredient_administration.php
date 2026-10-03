<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('shopping_categories')) {
            Schema::create('shopping_categories', function (Blueprint $table) {
                $table->id();
                $table->string('name')->unique();
                $table->string('emoji', 32)->nullable();
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamps();
            });
        }

        if (! Schema::hasColumn('users', 'is_admin')) {
            Schema::table('users', function (Blueprint $table) {
                $table->boolean('is_admin')->default(false)->after('avatar');
            });
        }

        if (! Schema::hasColumn('ingredients', 'shopping_category_id')) {
            Schema::table('ingredients', function (Blueprint $table) {
                $table->foreignId('shopping_category_id')
                    ->nullable()
                    ->after('default_unit')
                    ->constrained()
                    ->nullOnDelete();
            });
        }

        if (! Schema::hasColumn('ingredients', 'is_shoppable')) {
            Schema::table('ingredients', function (Blueprint $table) {
                $table->boolean('is_shoppable')->default(true)->after('shopping_category_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('ingredients', 'shopping_category_id')) {
            Schema::table('ingredients', function (Blueprint $table) {
                $table->dropConstrainedForeignId('shopping_category_id');
            });
        }

        if (Schema::hasColumn('ingredients', 'is_shoppable')) {
            Schema::table('ingredients', function (Blueprint $table) {
                $table->dropColumn('is_shoppable');
            });
        }

        if (Schema::hasColumn('users', 'is_admin')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('is_admin');
            });
        }

        Schema::dropIfExists('shopping_categories');
    }
};
