<?php

declare(strict_types=1);

namespace App\Domain\Deal\ValueObject;

use InvalidArgumentException;

/**
 * An amount of money held in minor units, so prices never suffer float drift.
 */
final readonly class Money
{
    private function __construct(
        public int $minorUnits,
        public string $currency,
    ) {}

    public static function fromMinorUnits(int $minorUnits, string $currency): self
    {
        if ($minorUnits < 0) {
            throw new InvalidArgumentException('A price cannot be negative.');
        }

        return new self($minorUnits, self::normalizeCurrency($currency));
    }

    /**
     * Builds from a major-unit amount, e.g. 119.99 PLN.
     */
    public static function fromDecimal(float|int|string $amount, string $currency): self
    {
        if (! is_numeric($amount)) {
            throw new InvalidArgumentException(sprintf('Amount [%s] is not numeric.', (string) $amount));
        }

        return self::fromMinorUnits((int) round((float) $amount * 100), $currency);
    }

    public function plus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->minorUnits + $other->minorUnits, $this->currency);
    }

    public function isAtMost(self $limit): bool
    {
        $this->assertSameCurrency($limit);

        return $this->minorUnits <= $limit->minorUnits;
    }

    public function isLessThan(self $other): bool
    {
        $this->assertSameCurrency($other);

        return $this->minorUnits < $other->minorUnits;
    }

    public function equals(self $other): bool
    {
        return $this->minorUnits === $other->minorUnits
            && $this->currency === $other->currency;
    }

    public function toDecimal(): float
    {
        return $this->minorUnits / 100;
    }

    public function format(): string
    {
        return sprintf('%s %s', number_format($this->toDecimal(), 2, ',', ' '), $this->currency);
    }

    private function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw new InvalidArgumentException(
                sprintf('Cannot compare %s with %s.', $this->currency, $other->currency),
            );
        }
    }

    private static function normalizeCurrency(string $currency): string
    {
        $normalized = strtoupper(trim($currency));

        if (preg_match('/^[A-Z]{3}$/', $normalized) !== 1) {
            throw new InvalidArgumentException(sprintf('Invalid currency code [%s].', $currency));
        }

        return $normalized;
    }
}
