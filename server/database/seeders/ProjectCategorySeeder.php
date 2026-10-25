<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ProjectCategorySeeder extends Seeder
{
    public function run(): void
    {
        $now = now();
        $categories = [];
        foreach (['Health', 'Wellness', 'Financial', 'Hobby'] as $index => $name) {
            $categories[] = [
                'name' => $name,
                'slug' => strtolower($name),
                'sort_order' => ($index + 1) * 10,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table('project_categories')->upsert($categories, ['slug'], ['name', 'sort_order', 'updated_at']);
    }
}
