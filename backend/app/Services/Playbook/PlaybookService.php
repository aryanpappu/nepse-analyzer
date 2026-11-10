<?php

declare(strict_types=1);

namespace App\Services\Playbook;

use App\Enums\InvestorProfile;
use App\Models\ProfileVerdict;
use App\Models\Stock;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Runs the engine over the market and persists the verdicts.
 *
 * Chunked so memory stays flat regardless of how many scrips are listed, and
 * upserted on (stock, profile, date) so re-running after the close simply
 * supersedes the midday scoring instead of duplicating it.
 */
final class PlaybookService
{
    public function __construct(
        private readonly PlaybookEngine $engine,
        private readonly PlaybookInputFactory $inputs,
    ) {}

    /**
     * Score every active, tradeable scrip for every profile.
     *
     * @param  list<InvestorProfile>|null  $profiles  Defaults to all.
     * @return array{scored: int, written: int, skipped: int}
     */
    public function rebuild(?array $profiles = null, ?Carbon $asOf = null, ?callable $onStock = null): array
    {
        $profiles ??= InvestorProfile::cases();
        $date = ($asOf ?? Carbon::today());
        $scored = 0;
        $written = 0;
        $skipped = 0;

        Stock::query()
            ->active()
            ->whereIn('instrument_type', ['equity', 'promoter', 'mutual_fund'])
            ->whereNotNull('last_price')
            ->with(['latestIndicator', 'latestFundamental'])
            ->chunkById(150, function (Collection $stocks) use (
                $profiles, $date, &$scored, &$written, &$skipped, $onStock
            ): void {
                $batch = $this->inputs->forStocks($stocks, $date);
                $rows = [];

                foreach ($stocks as $stock) {
                    $inputs = $batch->get($stock->id);

                    if ($inputs === null) {
                        $skipped++;

                        continue;
                    }

                    $produced = 0;

                    foreach ($profiles as $profile) {
                        $verdict = $this->engine->evaluate($inputs, $profile);

                        if ($verdict === null) {
                            continue;
                        }

                        $rows[] = $this->toRow($stock->id, $date, $verdict);
                        $produced++;
                    }

                    $produced > 0 ? $scored++ : $skipped++;

                    if ($onStock !== null) {
                        $onStock($stock->symbol, $produced);
                    }
                }

                if ($rows !== []) {
                    ProfileVerdict::upsert(
                        $rows,
                        ['stock_id', 'investor_profile', 'analysis_date'],
                        ['grade', 'score', 'reference_price', 'entry_low', 'entry_high', 'stop_loss',
                            'target_1', 'target_2', 'risk_reward', 'price_in_zone', 'scores', 'reasons',
                            'summary', 'updated_at'],
                    );
                    $written += count($rows);
                }
            });

        return ['scored' => $scored, 'written' => $written, 'skipped' => $skipped];
    }

    /**
     * Score one scrip live, without reading or writing the verdict table.
     *
     * Used by the stock detail endpoint so a freshly listed scrip — or one
     * scraped since the last playbook run — still gets an answer at today's
     * price rather than the last stored one.
     *
     * @return array<string, PlaybookVerdict> Keyed by profile value.
     */
    public function evaluateLive(Stock $stock, ?array $profiles = null, ?Carbon $asOf = null): array
    {
        $inputs = $this->inputs->forStock($stock, $asOf);

        if ($inputs === null) {
            return [];
        }

        $out = [];

        foreach ($profiles ?? InvestorProfile::cases() as $profile) {
            $verdict = $this->engine->evaluate($inputs, $profile);

            if ($verdict !== null) {
                $out[$profile->value] = $verdict;
            }
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private function toRow(int $stockId, Carbon $date, PlaybookVerdict $verdict): array
    {
        $now = Carbon::now();

        return [
            'stock_id' => $stockId,
            'investor_profile' => $verdict->profile->value,
            'analysis_date' => $date->toDateString(),
            'grade' => $verdict->grade->value,
            'score' => round($verdict->score, 2),
            'reference_price' => round($verdict->referencePrice, 2),
            'entry_low' => $verdict->entryLow,
            'entry_high' => $verdict->entryHigh,
            'stop_loss' => $verdict->stopLoss,
            'target_1' => $verdict->target1,
            'target_2' => $verdict->target2,
            'risk_reward' => $verdict->riskReward,
            'price_in_zone' => $verdict->priceInZone(),
            'scores' => json_encode($verdict->scores),
            'reasons' => json_encode($verdict->reasons),
            'summary' => $verdict->summary,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }
}
