<?php

declare(strict_types=1);

namespace App\Application\Deal;

use App\Domain\Deal\ValueObject\Airport;
use App\Domain\Deal\ValueObject\Money;
use App\Domain\Deal\ValueObject\SearchCriteria;
use App\Domain\Deal\ValueObject\StayWindow;
use Illuminate\Support\Facades\Date;

/**
 * Turns the watch list into the criteria a scan runs with. The window is
 * always relative to now, so a scheduled scan keeps rolling forward.
 */
final readonly class SearchCriteriaFactory
{
    /**
     * @param  list<string>  $departureIataCodes
     */
    public function __construct(
        private array $departureIataCodes,
        private int $windowDays,
        private Money $maxFlightPrice,
        private Money $maxRoundTripPrice,
        private Money $maxTripPrice,
        private StayWindow $stay,
    ) {}

    public function create(): SearchCriteria
    {
        $today = Date::now()->toImmutable()->startOfDay();

        return new SearchCriteria(
            departureAirports: array_map(
                static fn (string $iataCode): Airport => Airport::fromIataCode($iataCode),
                $this->departureIataCodes,
            ),
            departureFrom: $today,
            departureTo: $today->addDays($this->windowDays),
            maxFlightPrice: $this->maxFlightPrice,
            maxRoundTripPrice: $this->maxRoundTripPrice,
            maxTripPrice: $this->maxTripPrice,
            stay: $this->stay,
        );
    }
}
