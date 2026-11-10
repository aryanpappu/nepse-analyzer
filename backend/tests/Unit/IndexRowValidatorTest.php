<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Scraper\IndexHistoryBar;
use App\Services\Scraper\IndexRowValidator;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * IndexRowValidator deliberately carries no absolute bounds on an index's
 * *level* (see the class docblock) — every case here is about relative
 * movement and internal consistency instead, exercised with values far
 * outside any range NEPSE Index has actually traded at, to prove the
 * validator does not silently depend on one.
 */
final class IndexRowValidatorTest extends TestCase
{
    private IndexRowValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('nepse.index_validation.max_daily_change_percent', 20.0);

        $this->validator = new IndexRowValidator;
    }

    #[Test]
    public function it_rejects_a_zero_or_negative_value(): void
    {
        $this->assertFalse($this->validator->validate('NEPSE Index', 0.0)['valid']);
        $this->assertFalse($this->validator->validate('NEPSE Index', -100.0)['valid']);
    }

    #[Test]
    public function it_accepts_a_value_with_no_previous_reference_at_any_level(): void
    {
        // No absolute bounds: a plausible-looking first reading and an
        // extreme one (e.g. a rebased sub-index) are both accepted when there
        // is nothing to compare against yet.
        $this->assertTrue($this->validator->validate('NEPSE Index', 2552.58)['valid']);
        $this->assertTrue($this->validator->validate('NEPSE Index', 150.0)['valid']);
        $this->assertTrue($this->validator->validate('NEPSE Index', 987654.0)['valid']);
    }

    #[Test]
    public function it_rejects_a_day_on_day_move_beyond_the_configured_limit(): void
    {
        // 2500 -> 3200 is +28%, above the 20% limit.
        $verdict = $this->validator->validate('NEPSE Index', 3200.0, null, 2500.0, 1);

        $this->assertFalse($verdict['valid']);
        $this->assertStringContainsString('above the 20.00%', $verdict['reason']);
    }

    #[Test]
    public function it_accepts_a_day_on_day_move_within_the_configured_limit(): void
    {
        // 2500 -> 2600 is +4%.
        $verdict = $this->validator->validate('NEPSE Index', 2600.0, null, 2500.0, 1);

        $this->assertTrue($verdict['valid']);
    }

    #[Test]
    public function it_ignores_a_stale_previous_value_beyond_the_session_gap(): void
    {
        // The same +28% move is not judged against a previous value that is
        // too old to be "yesterday" — a missed scrape, not a real move.
        $verdict = $this->validator->validate('NEPSE Index', 3200.0, null, 2500.0, 10);

        $this->assertTrue($verdict['valid']);
    }

    #[Test]
    public function it_rejects_a_reported_percentage_that_disagrees_with_the_arithmetic(): void
    {
        // Value moved +4% against the previous value, but the widget reports
        // -12% — a mismapped column, the same failure mode PriceRowValidator
        // exists to catch for prices.
        $verdict = $this->validator->validate('NEPSE Index', 2600.0, -12.0, 2500.0, 1);

        $this->assertFalse($verdict['valid']);
        $this->assertStringContainsString('mismapped', $verdict['reason']);
    }

    #[Test]
    public function it_accepts_a_reported_percentage_that_matches_the_arithmetic(): void
    {
        $verdict = $this->validator->validate('NEPSE Index', 2600.0, 4.0, 2500.0, 1);

        $this->assertTrue($verdict['valid']);
    }

    #[Test]
    public function it_repairs_a_historical_bar_whose_close_falls_outside_its_own_range(): void
    {
        $bar = new IndexHistoryBar(
            name: 'NEPSE Index',
            date: Carbon::parse('2024-01-01'),
            value: 2700.0,
            open: 2600.0,
            high: 2650.0,
            low: 2590.0,
            changeAmount: 10.0,
            changePercent: 0.4,
            totalTurnover: 1_000_000.0,
        );

        $result = $this->validator->repair($bar);

        $this->assertTrue($result['repaired']);
        $this->assertNull($result['bar']->open);
        $this->assertNull($result['bar']->high);
        $this->assertNull($result['bar']->low);
        // The close (the figure benchmarks actually use) is kept as-is.
        $this->assertSame(2700.0, $result['bar']->value);
        $this->assertSame('NEPSE Index', $result['bar']->name);
    }

    #[Test]
    public function it_leaves_an_internally_consistent_bar_untouched(): void
    {
        $bar = new IndexHistoryBar(
            name: 'NEPSE Index',
            date: Carbon::parse('2024-01-01'),
            value: 2620.0,
            open: 2600.0,
            high: 2650.0,
            low: 2590.0,
        );

        $result = $this->validator->repair($bar);

        $this->assertFalse($result['repaired']);
        $this->assertSame($bar, $result['bar']);
    }
}
