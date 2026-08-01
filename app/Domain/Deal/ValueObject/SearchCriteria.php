<?php

declare(strict_types=1);

namespace App\Domain\Deal\ValueObject;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * What the owner is looking for. Sources translate this into their own query
 * language; sources that cannot filter (feeds) simply ignore the parts they
 * do not understand.
 */
final readonly class SearchCriteria
{
    /**
     * @param  list<Airport>  $departureAirports
     */
    public function __construct(
        public array $departureAirports,
        public CarbonImmutable $departureFrom,
        public CarbonImmutable $departureTo,
        public Money $maxFlightPrice,
        public Money $maxRoundTripPrice,
        public Money $maxTripPrice,
        public StayWindow $stay,
    ) {
        if ($departureAirports === []) {
            throw new InvalidArgumentException('At least one departure airport is required.');
        }

        if ($departureTo->lessThan($departureFrom)) {
            throw new InvalidArgumentException('The end of the search window precedes its start.');
        }
    }

    /**
     * @return list<string>
     */
    public function departureIataCodes(): array
    {
        return array_map(
            static fn (Airport $airport): string => $airport->iataCode,
            $this->departureAirports,
        );
    }

    public function coversDeparture(CarbonImmutable $departure): bool
    {
        return $departure->betweenIncluded($this->departureFrom, $this->departureTo);
    }

    /**
     * The last day a return leg could sensibly fall on.
     */
    public function latestReturn(): CarbonImmutable
    {
        return $this->stay->latestReturn($this->departureTo);
    }
}
