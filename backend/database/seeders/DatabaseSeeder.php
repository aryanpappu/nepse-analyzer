<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed shared reference data and an optional configured administrator.
     */
    public function run(): void
    {
        $this->call([
            SectorSeeder::class,
            AdminSeeder::class,
        ]);
    }
}
