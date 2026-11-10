<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The three ways people actually use this app.
 *
 * The same scrip is a different proposition to each of them: a bank trading at
 * a 20% discount to book with a flat chart is a long-term buy and a swing
 * trader's dead money. Rather than emit one blended verdict that suits nobody,
 * the playbook engine scores every scrip once per profile, and each profile
 * carries its own weights, hold band and risk geometry here.
 *
 * Percentages are expressed as fractions (0.15 = 15%).
 */
enum InvestorProfile: string
{
    case LongTerm = 'long_term';
    case Swing = 'swing';
    case Momentum = 'momentum';

    public static function default(): self
    {
        return self::LongTerm;
    }

    public function label(): string
    {
        return match ($this) {
            self::LongTerm => 'Long-term investor',
            self::Swing => 'Swing trader',
            self::Momentum => 'Momentum profit-taker',
        };
    }

    public function shortLabel(): string
    {
        return match ($this) {
            self::LongTerm => 'Long term',
            self::Swing => 'Swing',
            self::Momentum => 'Momentum',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::LongTerm => 'Buys businesses, not charts. Weighs earnings, book value, '
                .'dividends and the long trend; tolerates drawdowns and ignores weekly noise.',
            self::Swing => 'Rides the move between oversold and overbought. Leans on RSI, '
                .'MACD and the Bollinger band, with a stop close enough to be respected.',
            self::Momentum => 'Buys strength on volume and takes the money quickly. Small '
                .'targets, tight stops, and no interest in a story that needs a quarter to play out.',
        };
    }

    /**
     * Expected hold, in trading sessions, as [floor, ceiling].
     *
     * @return array{int, int}
     */
    public function holdSessions(): array
    {
        return match ($this) {
            self::LongTerm => [120, 750],
            self::Swing => [5, 30],
            self::Momentum => [2, 12],
        };
    }

    public function holdLabel(): string
    {
        return match ($this) {
            self::LongTerm => '6 months – 3 years',
            self::Swing => '1 – 6 weeks',
            self::Momentum => '3 – 14 days',
        };
    }

    public function timeHorizon(): TimeHorizon
    {
        return match ($this) {
            self::LongTerm => TimeHorizon::LongTerm,
            self::Swing => TimeHorizon::MediumTerm,
            self::Momentum => TimeHorizon::ShortTerm,
        };
    }

    /**
     * How much of the score each family of evidence is worth.
     *
     * Keys are the score buckets the engine fills. They sum to 1.0 so a profile
     * can be re-tuned without rescaling anything downstream.
     *
     * @return array<string, float>
     */
    public function weights(): array
    {
        return match ($this) {
            // A long-term buyer is mostly underwriting the business. Trend still
            // matters — no point catching a multi-year downtrend — but momentum
            // and volume are noise at this horizon.
            self::LongTerm => [
                'fundamentals' => 0.45,
                'valuation' => 0.20,
                'trend' => 0.25,
                'momentum' => 0.05,
                'volume' => 0.05,
            ],
            // A swing trader is buying a mean reversion inside a trend.
            self::Swing => [
                'fundamentals' => 0.10,
                'valuation' => 0.05,
                'trend' => 0.25,
                'momentum' => 0.45,
                'volume' => 0.15,
            ],
            // Momentum only cares that the move is real and being paid for.
            self::Momentum => [
                'fundamentals' => 0.05,
                'valuation' => 0.00,
                'trend' => 0.20,
                'momentum' => 0.45,
                'volume' => 0.30,
            ],
        };
    }

    /**
     * Target take-profit steps, as a fraction above the entry zone midpoint.
     *
     * @return array{float, float}
     */
    public function targetBands(): array
    {
        return match ($this) {
            self::LongTerm => [0.25, 0.60],
            self::Swing => [0.08, 0.15],
            self::Momentum => [0.04, 0.08],
        };
    }

    /**
     * Maximum tolerated loss from the entry zone midpoint before the thesis is
     * dead. ATR sets the actual stop; this caps how wide it may get.
     */
    public function maxStopLoss(): float
    {
        return match ($this) {
            self::LongTerm => 0.15,
            self::Swing => 0.05,
            self::Momentum => 0.03,
        };
    }

    /**
     * ATR multiple used to place the stop below the entry.
     *
     * Wider for long-term (weekly noise must not stop you out), tightest for
     * momentum (the trade is wrong the moment the thrust fails).
     */
    public function atrStopMultiple(): float
    {
        return match ($this) {
            self::LongTerm => 3.0,
            self::Swing => 1.8,
            self::Momentum => 1.0,
        };
    }

    /**
     * The RSI band this profile treats as a buyable dip.
     *
     * @return array{float, float}
     */
    public function buyableRsi(): array
    {
        return match ($this) {
            // A long-term buyer will happily buy a deeply hated stock.
            self::LongTerm => [0.0, 55.0],
            // A swing trader wants it oversold but turning, not in free fall.
            self::Swing => [30.0, 55.0],
            // Momentum buys strength; sub-50 RSI means there is no thrust yet.
            self::Momentum => [52.0, 72.0],
        };
    }

    /**
     * Minimum sessions of history before a verdict is meaningful.
     */
    public function minHistorySessions(): int
    {
        return match ($this) {
            self::LongTerm => 200,
            self::Swing => 50,
            self::Momentum => 30,
        };
    }

    /**
     * Position sizing hint, as a fraction of investable capital per scrip.
     *
     * Tighter stops let momentum size up without risking more rupees, but the
     * hit rate is lower, so the cap stays modest.
     */
    public function maxPositionWeight(): float
    {
        return match ($this) {
            self::LongTerm => 0.15,
            self::Swing => 0.10,
            self::Momentum => 0.07,
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
