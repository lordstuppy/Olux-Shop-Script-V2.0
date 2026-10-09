<?php

namespace Tests\Unit;

use App\Support\Money;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MoneyTest extends TestCase
{
    public function test_formats_minor_units(): void
    {
        $this->assertSame('25.00 EUR', Money::format(2500, 'EUR'));
        $this->assertSame('0.05 USD', Money::format(5, 'USD'));
        $this->assertSame('24.90', Money::toDecimal(2490, 'EUR'));
    }

    public function test_parse_received_truncates_extra_digits(): void
    {
        $this->assertSame(2490, Money::parseReceived('24.9', 'EUR'));
        $this->assertSame(2499, Money::parseReceived('24.999999', 'EUR'));
        $this->assertSame(2500, Money::parseReceived('25', 'EUR'));
    }

    #[DataProvider('invalidAmounts')]
    public function test_parse_rejects_malformed_amounts(string $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::parseReceived($value, 'USD');
    }

    public static function invalidAmounts(): array
    {
        return [['-1'], ['1e3'], ['abc'], [''], ['1,00'], ['12.']];
    }

    public function test_parse_input_rejects_too_many_decimals(): void
    {
        $this->assertSame(1999, Money::parseInput('19.99', 'USD'));
        $this->expectException(InvalidArgumentException::class);
        Money::parseInput('19.999', 'USD');
    }

    public function test_apply_bps_rounds_half_up(): void
    {
        $this->assertSame(200, Money::applyBps(1999, 1000));
        $this->assertSame(1, Money::applyBps(5, 1000));
        $this->assertSame(0, Money::applyBps(4, 1000));
    }

    public function test_allocate_sums_to_total(): void
    {
        $shares = Money::allocate(100, ['a' => 1, 'b' => 1, 'c' => 1]);
        $this->assertSame(100, array_sum($shares));
        $this->assertSame([34, 33, 33], array_values($shares));

        $this->assertSame([0, 0], Money::allocate(0, [5, 7]));
        $this->assertSame([250, 750], Money::allocate(1000, [1000, 3000]));
    }
}
