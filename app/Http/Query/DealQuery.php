<?php

declare(strict_types=1);

namespace App\Http\Query;

use App\Domain\Deal\DealSort;
use App\Domain\Deal\DealType;
use App\Domain\Deal\ValueObject\Airport;
use App\Domain\Deal\ValueObject\DealListing;
use App\Domain\Deal\ValueObject\HolidayWindow;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Throwable;

/**
 * How a URL describes a listing.
 *
 * The dashboard and the JSON API answer the same questions from the same query
 * string, so the reading of it lives here rather than in either delivery layer
 * - a filter added to one would otherwise silently be missing from the other.
 *
 * Nothing here validates: anything unusable is treated as no filter at all,
 * because both callers are a set of links, and a hand-edited one should show
 * everything rather than fail.
 */
final readonly class DealQuery
{
    private function __construct(public DealListing $listing) {}

    public static function fromRequest(Request $request, int $limit): self
    {
        $now = Date::now()->toImmutable();

        return new self(new DealListing(
            limit: $limit,
            sort: DealSort::tryFrom((string) $request->query('sort')) ?? DealSort::Score,
            now: $now,
            type: DealType::tryFrom((string) $request->query('type')),
            weekendsOnly: $request->boolean('weekends'),
            stealsOnly: $request->boolean('steals'),
            origin: self::airport($request->query('origin')),
            destination: self::airport($request->query('destination')),
            preferredOrigin: self::airport(config('deals.preferred_origin')),
            holiday: self::holiday($request->query('from'), $request->query('to')),
        ));
    }

    /**
     * The companion listing for blog offers whose article never named a date.
     * Without a holiday to match them against there is nothing to set them
     * apart, so they are only asked for once a search is narrowed to a window.
     */
    public function undatedTrips(): ?DealListing
    {
        if (! $this->listing->holiday instanceof HolidayWindow) {
            return null;
        }

        return new DealListing(
            limit: $this->listing->limit,
            sort: $this->listing->sort,
            now: $this->listing->now,
            stealsOnly: $this->listing->stealsOnly,
            undatedTripsOnly: true,
        );
    }

    /**
     * What was asked for, echoed back so a client can tell which filters took.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'sort' => $this->listing->sort->value,
            'type' => $this->listing->type?->value,
            'weekends' => $this->listing->weekendsOnly,
            'steals' => $this->listing->stealsOnly,
            'origin' => $this->listing->origin?->iataCode,
            'destination' => $this->listing->destination?->iataCode,
            'from' => $this->listing->holiday?->from->toDateString(),
            'to' => $this->listing->holiday?->to->toDateString(),
        ];
    }

    /**
     * The leave being searched against, if both ends were given and make sense.
     * A half-filled or backwards range is no filter at all.
     */
    private static function holiday(mixed $from, mixed $to): ?HolidayWindow
    {
        if (! is_string($from) || ! is_string($to)) {
            return null;
        }

        try {
            return new HolidayWindow(
                CarbonImmutable::createFromFormat('Y-m-d', $from)->startOfDay(),
                CarbonImmutable::createFromFormat('Y-m-d', $to)->startOfDay(),
            );
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Anything that is not a usable IATA code is treated as no filter at all.
     */
    private static function airport(mixed $iataCode): ?Airport
    {
        if (! is_string($iataCode) || trim($iataCode) === '') {
            return null;
        }

        try {
            return Airport::fromIataCode($iataCode);
        } catch (Throwable) {
            return null;
        }
    }
}
