<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The entry-side rating a profile gives a scrip you do not own yet.
 *
 * Deliberately not the same vocabulary as SignalType (buy/sell/hold): that one
 * answers "what does the AI think of this company", this one answers "should
 * this profile be putting money in at today's price". A scrip can be a fine
 * business and still be Wait for a swing trader who missed the entry.
 */
enum VerdictGrade: string
{
    case StrongBuy = 'strong_buy';
    case Buy = 'buy';
    case Watch = 'watch';
    case Neutral = 'neutral';
    case Avoid = 'avoid';

    /**
     * Bucket a 0-100 playbook score.
     */
    public static function fromScore(float $score): self
    {
        return match (true) {
            $score >= 78.0 => self::StrongBuy,
            $score >= 64.0 => self::Buy,
            $score >= 50.0 => self::Watch,
            $score >= 35.0 => self::Neutral,
            default => self::Avoid,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::StrongBuy => 'Strong buy',
            self::Buy => 'Buy',
            self::Watch => 'Watch',
            self::Neutral => 'Neutral',
            self::Avoid => 'Avoid',
        };
    }

    /**
     * Whether this grade justifies deploying capital now.
     */
    public function isActionable(): bool
    {
        return $this === self::StrongBuy || $this === self::Buy;
    }
}
