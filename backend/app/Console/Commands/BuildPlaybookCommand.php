<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\InvestorProfile;
use App\Services\Playbook\PlaybookService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class BuildPlaybookCommand extends Command
{
    protected $signature = 'nepse:playbook
        {--profile=* : Only score these profiles (long_term, swing, momentum).}
        {--date= : Score as at this date (YYYY-MM-DD). Defaults to today.}';

    protected $description = 'Score every scrip for every investor profile (long-term, swing, momentum)';

    public function handle(PlaybookService $playbook): int
    {
        $requested = (array) $this->option('profile');
        $profiles = [];

        foreach ($requested as $value) {
            $profile = InvestorProfile::tryFrom((string) $value);

            if ($profile === null) {
                $this->error(sprintf('Unknown profile "%s". Valid: %s.',
                    $value, implode(', ', InvestorProfile::values())));

                return self::FAILURE;
            }

            $profiles[] = $profile;
        }

        $profiles = $profiles === [] ? InvestorProfile::cases() : $profiles;
        $date = $this->option('date') !== null ? Carbon::parse((string) $this->option('date')) : Carbon::today();

        $this->info(sprintf('Scoring %s for %s.',
            implode(', ', array_map(static fn (InvestorProfile $p): string => $p->shortLabel(), $profiles)),
            $date->toDateString(),
        ));

        $started = microtime(true);
        $result = $playbook->rebuild($profiles, $date);
        $seconds = round(microtime(true) - $started, 1);

        $this->newLine();
        $this->table(
            ['Scrips scored', 'Verdicts written', 'Skipped', 'Duration'],
            [[
                number_format($result['scored']),
                number_format($result['written']),
                number_format($result['skipped']),
                $seconds.' s',
            ]],
        );

        if ($result['skipped'] > 0) {
            $this->comment(sprintf('%d scrips were skipped — usually too little price history for the '
                .'profile minimum, or no last price yet.', $result['skipped']));
        }

        return self::SUCCESS;
    }
}
