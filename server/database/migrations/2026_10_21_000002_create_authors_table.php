<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('authors', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('youtube_link', 2048)->nullable();
            $table->string('website_link', 2048)->nullable();
            $table->string('icon')->nullable();
            $table->timestamps();
        });
        Schema::table('recipes', function (Blueprint $table) {
            $table->foreignId('author_id')->nullable()->constrained('authors')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('recipes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('author_id');
        });
        Schema::dropIfExists('authors');
    }
};
