<?php

use App\Enums\ProjectSharingMode;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_collaborators', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->enum('sharing_mode', array_column(ProjectSharingMode::cases(), 'value'));
            $table->timestamps();
            $table->unique(['project_id', 'user_id']);
            $table->unique(['project_id', 'id'], 'project_collaborators_project_identity_unique');
            $table->index(['user_id', 'project_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_collaborators');
    }
};
