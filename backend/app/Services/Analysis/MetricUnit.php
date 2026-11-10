<?php

declare(strict_types=1);

namespace App\Services\Analysis;

/**
 * How a metric should be read, so the API, the table and the prompt render the
 * same number the same way instead of each inventing a format.
 */
enum MetricUnit: string
{
    case Rupees = 'rupees';
    case Percent = 'percent';
    case Times = 'times';
    case Shares = 'shares';

    /**
     * Human-readable form.
     *
     * Large rupee figures are abbreviated to millions or billions: NEPSE
     * balance sheets run to twelve digits, and an unabbreviated capital figure
     * is unreadable in a comparison table and burns prompt tokens for no gain.
     */
    public function format(?float $value): string
    {
        if ($value === null) {
            return '—';
        }

        return match ($this) {
            self::Percent => number_format($value, 2).'%',
            self::Times => number_format($value, 2).'x',
            self::Shares => number_format($value),
            self::Rupees => $this->rupees($value),
        };
    }

    private function rupees(float $value): string
    {
        $abs = abs($value);

        if ($abs >= 1_000_000_000) {
            return 'NPR '.number_format($value / 1_000_000_000, 2).'B';
        }

        if ($abs >= 1_000_000) {
            return 'NPR '.number_format($value / 1_000_000, 0).'M';
        }

        return 'NPR '.number_format($value, 2);
    }
}
