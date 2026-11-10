<?php

declare(strict_types=1);

namespace App\Services\Playbook\Backtest;

use App\Enums\InvestorProfile;
use Illuminate\Support\Carbon;

/**
 * Everything a backtest run is parameterised by, in one immutable object.
 *
 * Defaults come from `config('nepse.backtest.*')` with in-code fallbacks, so
 * the command works on a checkout with no new config keys and the numbers are
 * still tunable in one place once they are added.
 */
final class BacktestSettings
{
    /**
     * @param  list<InvestorProfile>  $profiles
     * @param  list<string>  $symbols  Empty means the whole eligible universe.
     */
    public function __construct(
        public readonly Carbon $from,
        public readonly Carbon $to,
        public readonly array $profiles,
        public readonly array $symbols = [],
        /** Sessions between signal dates. 1 samples every session; higher is a coarser but faster sweep. */
        public readonly int $intervalSessions = 10,
        /** Sessions after a signal in which the entry band must trade, or the plan lapses. */
        public readonly int $entryWindowSessions = 5,
        /** Overrides the profile's own hold ceiling. Null uses the profile. */
        public readonly ?int $maxHoldSessions = null,
        /** @var 'target_1'|'target_2' */
        public readonly string $exitTarget = 'target_1',
        public readonly float $notionalPerTrade = 100_000.0,
        public readonly FundamentalsMode $fundamentalsMode = FundamentalsMode::None,
        public readonly ?string $marketIndexSlug = null,
        /** Cap on the eligible universe, for a quick smoke run. Null means no cap. */
        public readonly ?int $stockLimit = null,
        /** Instrument types the replay will score. Debentures, promoter and fund units are out by design. */
        public readonly array $instrumentTypes = ['equity'],
    ) {}

    /**
     * @param  list<InvestorProfile>  $profiles
     * @param  list<string>  $symbols
     */
    public static function make(
        Carbon $from,
        Carbon $to,
        array $profiles,
        array $symbols = [],
        ?int $intervalSessions = null,
        ?int $entryWindowSessions = null,
        ?int $maxHoldSessions = null,
        ?string $exitTarget = null,
        ?float $notionalPerTrade = null,
        ?FundamentalsMode $fundamentalsMode = null,
        ?string $marketIndexSlug = null,
        ?int $stockLimit = null,
    ): self {
        return new self(
            from: $from->copy()->startOfDay(),
            to: $to->copy()->startOfDay(),
            profiles: $profiles,
            symbols: array_values(array_unique(array_map('strtoupper', $symbols))),
            intervalSessions: max(1, $intervalSessions ?? (int) config('nepse.backtest.interval_sessions', 10)),
            entryWindowSessions: max(1, $entryWindowSessions ?? (int) config('nepse.backtest.entry_window_sessions', 5)),
            maxHoldSessions: $maxHoldSessions === null ? null : max(1, $maxHoldSessions),
            exitTarget: $exitTarget === 'target_2' ? 'target_2' : 'target_1',
            notionalPerTrade: max(1.0, $notionalPerTrade ?? (float) config('nepse.backtest.notional_per_trade', 100_000)),
            fundamentalsMode: $fundamentalsMode ?? FundamentalsMode::from(
                (string) config('nepse.backtest.fundamentals_mode', FundamentalsMode::None->value)
            ),
            marketIndexSlug: $marketIndexSlug,
            stockLimit: $stockLimit === null ? null : max(1, $stockLimit),
            instrumentTypes: array_values(array_filter(array_map(
                'strval',
                (array) config('nepse.backtest.instrument_types', ['equity']),
            ))),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'from' => $this->from->toDateString(),
            'to' => $this->to->toDateString(),
            'profiles' => array_map(static fn (InvestorProfile $p): string => $p->value, $this->profiles),
            'symbols' => $this->symbols === [] ? 'all eligible' : $this->symbols,
            'interval_sessions' => $this->intervalSessions,
            'entry_window_sessions' => $this->entryWindowSessions,
            'max_hold_sessions' => $this->maxHoldSessions ?? 'per profile',
            'exit_target' => $this->exitTarget,
            'notional_per_trade' => $this->notionalPerTrade,
            'fundamentals_mode' => $this->fundamentalsMode->value,
            'instrument_types' => $this->instrumentTypes,
            'stock_limit' => $this->stockLimit,
        ];
    }
}
