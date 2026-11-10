<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Analysis\MetricCatalog;
use App\Services\Analysis\MetricDirection;
use App\Services\Analysis\MetricUnit;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MetricDirectionTest extends TestCase
{
    #[Test]
    public function higher_is_better_ranks_the_larger_value_first(): void
    {
        $this->assertGreaterThan(0, MetricDirection::Higher->compare(14.4, 11.2));
        $this->assertLessThan(0, MetricDirection::Higher->compare(11.2, 14.4));
    }

    #[Test]
    public function lower_is_better_inverts_the_ordering(): void
    {
        $this->assertGreaterThan(0, MetricDirection::Lower->compare(18.8, 19.3));
        $this->assertLessThan(0, MetricDirection::Lower->compare(19.3, 18.8));
    }

    /**
     * Neutral metrics must never produce a winner — this is what stops the
     * cheaper share price from being scored as the better company.
     */
    #[Test]
    public function neutral_never_separates_two_values(): void
    {
        $this->assertSame(0, MetricDirection::Neutral->compare(725.0, 552.0));
        $this->assertSame(0, MetricDirection::Neutral->compare(552.0, 725.0));
    }

    #[Test]
    public function share_price_and_market_cap_are_context_only(): void
    {
        $catalog = MetricCatalog::keyed();

        $this->assertFalse($catalog['last_price']->isScored());
        $this->assertFalse($catalog['market_capitalization']->isScored());
    }

    #[Test]
    public function every_scored_metric_states_why_its_direction_is_what_it_is(): void
    {
        foreach (MetricCatalog::scored() as $metric) {
            $this->assertNotSame('', $metric->rationale, "{$metric->key} has no rationale.");
        }
    }

    #[Test]
    public function metric_keys_are_unique(): void
    {
        $keys = array_map(static fn ($m): string => $m->key, MetricCatalog::all());

        $this->assertSame($keys, array_unique($keys));
    }

    #[Test]
    public function large_rupee_figures_are_abbreviated_rather_than_printed_in_full(): void
    {
        $this->assertSame('NPR 32.06B', MetricUnit::Rupees->format(32_056_997_000.0));
        $this->assertSame('NPR 715.00', MetricUnit::Rupees->format(715.0));
        $this->assertSame('5.61%', MetricUnit::Percent->format(5.6104));
        $this->assertSame('19.28x', MetricUnit::Times->format(19.28));
    }

    #[Test]
    public function a_missing_value_renders_as_a_dash_not_a_zero(): void
    {
        $this->assertSame('—', MetricUnit::Percent->format(null));
        $this->assertSame('—', MetricUnit::Rupees->format(null));
    }
}
