<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DailyStructureSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
      DB::table('daily_structures')->upsert([
        [
          'name' => 'Mic dejun',
          'slug' => 'breakfast',
          'sort_order' => 10,
        ],
        [
          'name' => 'Gustare',
          'slug' => 'snack',
          'sort_order' => 20,
        ],
        [
          'name' => 'Prânz',
          'slug' => 'lunch',
          'sort_order' => 30,
        ],
        [
          'name' => 'Cină',
          'slug' => 'dinner',
          'sort_order' => 40,
        ],
      ], ['slug'], [
        'name',
        'sort_order',
      ]);
    }
}
