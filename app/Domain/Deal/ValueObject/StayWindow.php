<?php

declare(strict_types=1);

namespace App\Domain\Deal\ValueObject;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * How long a trip may last to be worth taking.
 *
 * A 40 PLN seat out is no bargain if the way back is a month later - nobody
 * gets that much leave. Sources are told this window so they never offer a
 * pairing that could not be booked off work.
 */
final readonly class StayWindow
{
    public function __construct(
        public int $minimumNights,
        public int $maximumNights,
    ) {
        if ($minimumNights < 1) {
            throw new InvalidArgumentException('A stay lasts at least one night.');
        }

        if ($maximumNights < $minimumNights) {
            throw new InvalidArgumentException('The longest stay cannot be shorter than the shortest one.');
        }
    }

    public function allows(CarbonImmutable $departure, CarbonImmutable $return): bool
    {
        $nights = (int) $departure->startOfDay()->diffInDays($return->startOfDay());

        return $nights >= $this->minimumNights && $nights <= $this->maximumNights;
    }

    public function earliestReturn(CarbonImmutable $departure): CarbonImmutable
    {
        return $departure->addDays($this->minimumNights);
    }

    public function latestReturn(CarbonImmutable $departure): CarbonImmutable
    {
        return $departure->addDays($this->maximumNights);
    }
}
