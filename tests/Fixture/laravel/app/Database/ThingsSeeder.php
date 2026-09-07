<?php

declare(strict_types=1);

namespace App\Database;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

final class ThingsSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('things')->insert(['name' => 'seeded']);
    }
}
