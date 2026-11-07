<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('shopping_categories')->insertOrIgnore([
            'name' => 'Farmacie', 'emoji' => '💊', 'sort_order' => 100,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        // Preserve this shared category and any references created after deployment.
    }
};
