<?php

declare(strict_types=1);

namespace App\Services\Scraper;

use Illuminate\Support\Str;

final readonly class IndexRow
{
    public function __construct(
        public string $name,
        public float $value,
        public ?float $changeAmount = null,
        public ?float $changePercent = null,
        public ?float $totalTurnover = null,
        public ?int $totalVolume = null,
        public ?int $totalTransactions = null,
        public ?int $advances = null,
        public ?int $declines = null,
        public ?int $unchanged = null,
    ) {}

    public function slug(): string
    {
        return Str::slug($this->name);
    }
}
