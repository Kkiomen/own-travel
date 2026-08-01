<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Deal;

use App\Domain\Deal\ValueObject\Money;
use App\Domain\Deal\ValueObject\PriceBand;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class PriceBandTest extends TestCase
{
    public function test_at_or_under_the_great_price_scores_full_marks(): void
    {
        $band = $this->band(100, 350);

        $this->assertSame(100, $band->rate(Money::fromDecimal(50, 'PLN')));
        $this->assertSame(100, $band->rate(Money::fromDecimal(100, 'PLN')));
    }

    public function test_at_or_over_the_poor_price_scores_nothing(): void
    {
        $band = $this->band(100, 350);

        $this->assertSame(0, $band->rate(Money::fromDecimal(350, 'PLN')));
        $this->assertSame(0, $band->rate(Money::fromDecimal(900, 'PLN')));
    }

    public function test_it_scales_linearly_in_between(): void
    {
        $band = $this->band(100, 300);

        $this->assertSame(75, $band->rate(Money::fromDecimal(150, 'PLN')));
        $this->assertSame(50, $band->rate(Money::fromDecimal(200, 'PLN')));
        $this->assertSame(25, $band->rate(Money::fromDecimal(250, 'PLN')));
    }

    public function test_it_refuses_an_upside_down_band(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->band(350, 100);
    }

    private function band(float $great, float $poor): PriceBand
    {
        return new PriceBand(Money::fromDecimal($great, 'PLN'), Money::fromDecimal($poor, 'PLN'));
    }
}
