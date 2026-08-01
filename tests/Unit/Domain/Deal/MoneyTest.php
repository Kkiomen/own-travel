<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Deal;

use App\Domain\Deal\ValueObject\Money;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class MoneyTest extends TestCase
{
    public function test_it_keeps_decimal_amounts_exact(): void
    {
        $money = Money::fromDecimal(119.99, 'PLN');

        $this->assertSame(11999, $money->minorUnits);
        $this->assertSame(119.99, $money->toDecimal());
    }

    public function test_it_rounds_to_the_nearest_minor_unit(): void
    {
        $this->assertSame(2999, Money::fromDecimal('29.994', 'PLN')->minorUnits);
        $this->assertSame(3000, Money::fromDecimal('29.995', 'PLN')->minorUnits);
    }

    public function test_it_normalizes_the_currency_code(): void
    {
        $this->assertSame('PLN', Money::fromDecimal(10, 'pln')->currency);
    }

    public function test_it_compares_against_a_threshold(): void
    {
        $limit = Money::fromDecimal(150, 'PLN');

        $this->assertTrue(Money::fromDecimal(149.99, 'PLN')->isAtMost($limit));
        $this->assertTrue(Money::fromDecimal(150, 'PLN')->isAtMost($limit));
        $this->assertFalse(Money::fromDecimal(150.01, 'PLN')->isAtMost($limit));
    }

    public function test_it_refuses_to_compare_different_currencies(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::fromDecimal(100, 'PLN')->isAtMost(Money::fromDecimal(100, 'EUR'));
    }

    public function test_it_rejects_a_negative_amount(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::fromDecimal(-1, 'PLN');
    }

    public function test_it_rejects_an_invalid_currency_code(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::fromDecimal(10, 'PLNN');
    }

    public function test_it_formats_for_display(): void
    {
        $this->assertSame('1 234,50 PLN', Money::fromDecimal(1234.5, 'PLN')->format());
    }
}
