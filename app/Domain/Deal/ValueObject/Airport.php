<?php

declare(strict_types=1);

namespace App\Domain\Deal\ValueObject;

use InvalidArgumentException;

final readonly class Airport
{
    private function __construct(
        public string $iataCode,
        public ?string $name,
        public ?string $countryName,
    ) {}

    public static function fromIataCode(string $iataCode, ?string $name = null, ?string $countryName = null): self
    {
        $code = strtoupper(trim($iataCode));

        if (preg_match('/^[A-Z]{3}$/', $code) !== 1) {
            throw new InvalidArgumentException(sprintf('Invalid IATA code [%s].', $iataCode));
        }

        return new self($code, $name, $countryName);
    }

    public function label(): string
    {
        return $this->name ?? $this->iataCode;
    }

    public function equals(self $other): bool
    {
        return $this->iataCode === $other->iataCode;
    }
}
