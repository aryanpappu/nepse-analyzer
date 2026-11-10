<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Sector;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * The NEPSE sector taxonomy. Seeded up front so stocks can be classified even
 * before the fundamentals crawler has run.
 */
class SectorSeeder extends Seeder
{
    private const SECTORS = [
        'Commercial Bank' => 'Class "A" commercial banks licensed by Nepal Rastra Bank.',
        'Development Bank' => 'Class "B" national and regional development banks.',
        'Finance' => 'Class "C" finance companies.',
        'Microfinance' => 'Class "D" microfinance institutions.',
        'Life Insurance' => 'Life insurers regulated by the Nepal Insurance Authority.',
        'Non-Life Insurance' => 'General and non-life insurers.',
        'Hydropower' => 'Hydroelectric power generation and development companies.',
        'Manufacturing and Processing' => 'Industrial manufacturing and processing companies.',
        'Hotel & Tourism' => 'Hotels, resorts and tourism operators.',
        'Trading' => 'Trading houses and distributors.',
        'Investment' => 'Investment and holding companies.',
        'Mutual Fund' => 'Closed-end mutual fund schemes.',
        'Corporate Debentures' => 'Listed corporate debentures and bonds.',
        'Government Bonds' => 'Government issued bonds.',
        'Preference Share' => 'Listed preference shares.',
        'Promoter Share' => 'Promoter group shares traded separately from ordinary shares.',
        'Others' => 'Companies that do not fit the other classifications.',
    ];

    public function run(): void
    {
        foreach (self::SECTORS as $name => $description) {
            Sector::updateOrCreate(
                ['slug' => Str::slug($name)],
                ['name' => $name, 'description' => $description],
            );
        }

        $this->command?->info('Seeded '.count(self::SECTORS).' NEPSE sectors.');
    }
}
