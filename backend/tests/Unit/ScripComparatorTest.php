<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Analysis\FundamentalSnapshot;
use App\Services\Analysis\ScripComparator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Scoring rules only — no database. compareSnapshots() is the pure half of the
 * comparator precisely so these rules can be pinned down here.
 */
final class ScripComparatorTest extends TestCase
{
    private function row(array $result, string $key): array
    {
        foreach ($result['metrics'] as $metric) {
            if ($metric['key'] === $key) {
                return $metric;
            }
        }

        $this->fail("No metric row for {$key}.");
    }

    /**
     * The real EBL/NABIL figures from the filings this feature was built
     * against, so the test fails if the scoring conventions silently change.
     */
    private function ebl(): FundamentalSnapshot
    {
        return FundamentalSnapshot::of('EBL', [
            'share_capital' => 13_721_376_000.0,
            'distributable_eps' => 40.1141,
            'distributable_eps_yield' => 5.6104,
            'eps' => 36.95,
            'pe_ratio' => 18.81,
            'book_value' => 256.47,
            'pb_ratio' => 2.71,
            'roe' => 14.41,
            'npl_percent' => 0.49,
            'last_price' => 715.0,
        ], sector: 'Commercial Bank', fiscalPeriod: '2082/2083 Q4');
    }

    private function nabil(): FundamentalSnapshot
    {
        return FundamentalSnapshot::of('NABIL', [
            'share_capital' => 32_056_997_000.0,
            'distributable_eps' => 19.1047,
            'distributable_eps_yield' => 3.4774,
            'eps' => 27.74,
            'pe_ratio' => 19.28,
            'book_value' => 247.28,
            'pb_ratio' => 2.16,
            'roe' => 11.22,
            'npl_percent' => 4.20,
            'last_price' => 549.40,
        ], sector: 'Commercial Bank', fiscalPeriod: '2082/2083 Q4');
    }

    private function compare(FundamentalSnapshot ...$snapshots): array
    {
        return (new ScripComparator)->compareSnapshots($snapshots);
    }

    #[Test]
    public function a_smaller_capital_base_wins_on_share_capital(): void
    {
        $result = $this->compare($this->ebl(), $this->nabil());

        $this->assertSame('EBL', $this->row($result, 'share_capital')['winner']);
    }

    #[Test]
    public function a_lower_pe_wins_and_a_higher_roe_wins(): void
    {
        $result = $this->compare($this->ebl(), $this->nabil());

        $this->assertSame('EBL', $this->row($result, 'pe_ratio')['winner']);
        $this->assertSame('EBL', $this->row($result, 'roe')['winner']);
        $this->assertSame('NABIL', $this->row($result, 'pb_ratio')['winner']);
    }

    #[Test]
    public function fewer_non_performing_loans_wins(): void
    {
        $this->assertSame('EBL', $this->row($this->compare($this->ebl(), $this->nabil()), 'npl_percent')['winner']);
    }

    /**
     * The single most important scoring rule: the cheaper share is not the
     * better company, and must never earn a point for being numerically lower.
     */
    #[Test]
    public function the_cheaper_share_price_does_not_win_anything(): void
    {
        $result = $this->compare($this->ebl(), $this->nabil());
        $row = $this->row($result, 'last_price');

        $this->assertNull($row['winner']);
        $this->assertFalse($row['scored']);
        $this->assertFalse($row['contested']);
    }

    #[Test]
    public function a_metric_only_one_side_reports_is_excluded_rather_than_awarded(): void
    {
        $onlyOneHasRoe = FundamentalSnapshot::of('AAA', ['roe' => 20.0, 'pe_ratio' => 10.0]);
        $noRoe = FundamentalSnapshot::of('BBB', ['pe_ratio' => 12.0]);

        $result = $this->compare($onlyOneHasRoe, $noRoe);
        $roe = $this->row($result, 'roe');

        $this->assertFalse($roe['contested'], 'A metric only one side reports must not be contested.');
        $this->assertNull($roe['winner']);
        $this->assertSame(0, $result['scoreboard']['AAA'] + $result['scoreboard']['BBB'] - 1);
    }

    #[Test]
    public function identical_values_are_recorded_as_a_tie_not_broken_arbitrarily(): void
    {
        $a = FundamentalSnapshot::of('AAA', ['roe' => 12.5]);
        $b = FundamentalSnapshot::of('BBB', ['roe' => 12.5]);

        $row = $this->row($this->compare($a, $b), 'roe');

        $this->assertTrue($row['tie']);
        $this->assertNull($row['winner']);
    }

    #[Test]
    public function an_even_split_produces_no_overall_winner(): void
    {
        $a = FundamentalSnapshot::of('AAA', ['roe' => 20.0, 'pe_ratio' => 15.0]);
        $b = FundamentalSnapshot::of('BBB', ['roe' => 10.0, 'pe_ratio' => 12.0]);

        $result = $this->compare($a, $b);

        $this->assertSame(1, $result['scoreboard']['AAA']);
        $this->assertSame(1, $result['scoreboard']['BBB']);
        $this->assertNull($result['verdict']['winner']);
    }

    #[Test]
    public function with_nothing_in_common_there_is_no_verdict(): void
    {
        $a = FundamentalSnapshot::of('AAA', ['roe' => 20.0]);
        $b = FundamentalSnapshot::of('BBB', ['pe_ratio' => 12.0]);

        $result = $this->compare($a, $b);

        $this->assertSame(0, $result['contested_metrics']);
        $this->assertNull($result['verdict']['winner']);
        $this->assertStringContainsString('nothing to compare', $result['verdict']['note']);
    }

    #[Test]
    public function the_verdict_names_the_leader_and_the_margin(): void
    {
        $result = $this->compare($this->ebl(), $this->nabil());

        $this->assertSame('EBL', $result['verdict']['winner']);
        $this->assertGreaterThan(0, $result['verdict']['margin']);
        $this->assertSame(
            $result['contested_metrics'],
            array_sum($result['scoreboard']),
            'Every contested metric should award exactly one point.',
        );
    }

    #[Test]
    public function coverage_reports_how_much_is_actually_known(): void
    {
        $thin = FundamentalSnapshot::of('AAA', ['roe' => 20.0]);

        $this->assertGreaterThan(0.0, $thin->coverage());
        $this->assertLessThan(20.0, $thin->coverage());
        $this->assertGreaterThan($thin->coverage(), $this->ebl()->coverage());
    }

    #[Test]
    public function it_refuses_fewer_than_two_scrips(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new ScripComparator)->compare(['NABIL']);
    }

    #[Test]
    public function it_refuses_a_duplicate_symbol_pair(): void
    {
        // "NABIL vs NABIL" collapses to one distinct symbol and is rejected
        // before any query runs.
        $this->expectException(\InvalidArgumentException::class);

        (new ScripComparator)->compare(['NABIL', 'nabil']);
    }
}
