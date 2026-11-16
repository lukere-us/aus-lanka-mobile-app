<?php

namespace Tests\Unit;

use App\Support\Money;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    public function test_parses_and_formats_without_float_errors(): void
    {
        $a = Money::of('0.10', 'LKR');
        $b = Money::of('0.20', 'LKR');

        $this->assertSame('0.30', $a->add($b)->toDecimal());
        $this->assertSame('4500.00', Money::of('1500', 'LKR')->multiply(3)->toDecimal());
        $this->assertSame('-12.05', Money::of('-12.05', 'LKR')->toDecimal());
        $this->assertSame(['amount' => '2.50', 'currency' => 'LKR'], Money::of('2.5', 'lkr')->toArray());
    }

    public function test_percentage_rounds_half_up(): void
    {
        // 7.5% commission of LKR 1,999.99 = 149.99925 -> 150.00
        $this->assertSame('150.00', Money::of('1999.99', 'LKR')->percentage(750)->toDecimal());
        $this->assertSame('0.01', Money::of('0.10', 'LKR')->percentage(500)->toDecimal());
    }

    public function test_rejects_currency_mismatch_and_bad_input(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::of('1.00', 'LKR')->add(Money::of('1.00', 'USD'));
    }

    public function test_rejects_more_than_two_decimals(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::of('1.005', 'LKR');
    }
}
