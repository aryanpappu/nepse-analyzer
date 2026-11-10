<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Numeric;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class NumericTest extends TestCase
{
    /**
     * The distinction that matters most: a placeholder means "no trade", and
     * coercing it to 0 would write a zero close into the price history.
     */
    #[DataProvider('nullTokens')]
    public function test_placeholders_parse_to_null_not_zero(string $input): void
    {
        $this->assertNull(Numeric::parse($input), "\"{$input}\" must not become 0");
    }

    public static function nullTokens(): array
    {
        return [
            ['-'], ['--'], ['N/A'], ['n/a'], ['NA'], [''], ['   '], ['null'], ['.'],
        ];
    }

    public function test_thousands_separators_and_currency_are_stripped(): void
    {
        $this->assertSame(1234.56, Numeric::parse('1,234.56'));
        $this->assertSame(25249128.30, Numeric::parse('25,249,128.30'));
        $this->assertSame(938.90, Numeric::parse('Rs. 938.90'));
        $this->assertSame(3.0, Numeric::parse('3.00 %'));
    }

    public function test_non_breaking_spaces_are_handled(): void
    {
        $this->assertSame(558.0, Numeric::parse("558.00\xc2\xa0"));
    }

    public function test_negatives_in_both_conventions(): void
    {
        $this->assertSame(-0.11, Numeric::parse('-0.11'));
        // Accounting parentheses.
        $this->assertSame(-1234.0, Numeric::parse('(1,234)'));
    }

    public function test_malformed_numbers_are_rejected_rather_than_truncated(): void
    {
        // Silently reading "12.3.4" as 12.3 would be worse than refusing it.
        $this->assertNull(Numeric::parse('12.3.4'));
        $this->assertNull(Numeric::parse('1-2'));
        $this->assertNull(Numeric::parse('abc'));
    }

    public function test_parse_int_rounds(): void
    {
        $this->assertSame(831, Numeric::parseInt('831.00'));
        $this->assertSame(78882, Numeric::parseInt('78,882'));
        $this->assertNull(Numeric::parseInt('-'));
    }

    public function test_text_collapses_whitespace(): void
    {
        $this->assertSame('52 Weeks High', Numeric::text("  52   Weeks\n High "));
        $this->assertSame('', Numeric::text(null));
    }

    public function test_percent_change_is_divide_by_zero_safe(): void
    {
        $this->assertSame(10.0, Numeric::percentChange(100.0, 110.0));
        $this->assertNull(Numeric::percentChange(0.0, 110.0));
        $this->assertNull(Numeric::percentChange(null, 110.0));
        $this->assertNull(Numeric::percentChange(100.0, null));
    }
}
