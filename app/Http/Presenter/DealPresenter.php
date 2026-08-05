<?php

declare(strict_types=1);

namespace App\Http\Presenter;

use App\Domain\Deal\BoardType;
use App\Domain\Deal\Deal;
use App\Domain\Deal\DealType;
use App\Domain\Deal\Service\Steal;
use App\Domain\Deal\ValueObject\Airport;
use App\Domain\Deal\ValueObject\DealSummary;
use App\Domain\Deal\ValueObject\TravelWindow;

/**
 * The one shape a deal leaves this app in.
 *
 * The dashboard and the JSON API show the same offers, so they are drawn from
 * the same presenter - a second copy would drift, and the API is meant to be
 * the dashboard's data, not a lookalike.
 *
 * Numbers and codes go out, never formatted strings: currency, dates and
 * durations are the reader's business, and every reader formats differently.
 */
final readonly class DealPresenter
{
    public function __construct(private Steal $steal) {}

    /**
     * @param  list<Deal>  $deals
     * @return list<array<string, mixed>>
     */
    public function all(array $deals): array
    {
        return array_map($this->deal(...), $deals);
    }

    /**
     * @return array<string, mixed>
     */
    public function deal(Deal $deal): array
    {
        return [
            'id' => $deal->fingerprint(),
            'source' => $deal->source,
            'type' => $deal->type->value,
            'title' => $deal->title,
            'price' => $deal->price->toDecimal(),
            'currency' => $deal->price->currency,
            'url' => $deal->url,
            'origin' => $this->endpoint($deal->origin),
            'destination' => $this->endpoint($deal->destination),
            'departs_at' => $deal->departsAt?->toIso8601String(),
            'returns_at' => $deal->returnsAt?->toIso8601String(),
            'published_at' => $deal->publishedAt?->toIso8601String(),
            'weekend' => $deal->weekendGetaway,
            'steal' => $this->steal->matches($deal),
            'typical_price' => $deal->typicalPrice?->toDecimal(),
            'discount' => $deal->discount() === null ? null : (int) round($deal->discount() * 100),
            'days' => $deal->trip?->days,
            'board' => $deal->trip === null || $deal->trip->board === BoardType::Unknown
                ? null
                : $deal->trip->board->value,
            'hotel_stars' => $deal->trip?->hotelStars,
            'trip_destination' => $deal->trip?->destination,
            'hotel' => $deal->trip?->hotel,
            'departure_cities' => $deal->trip->departureCities ?? [],
            'dates' => array_map(
                static fn (TravelWindow $window): array => [
                    'from' => $window->from->toDateString(),
                    'to' => $window->to?->toDateString(),
                    'label' => $window->label,
                ],
                $deal->trip->dates ?? [],
            ),
            'highlights' => $deal->trip->highlights ?? [],
            'has_details' => $deal->trip?->areWorthShowing() ?? false,
            'score' => $deal->score?->value,
            'price_per_day' => $deal->score?->pricePerDay()?->toDecimal(),
        ];
    }

    /**
     * @param  list<Airport>  $airports
     * @return list<array{code: string, label: string}>
     */
    public function airports(array $airports): array
    {
        return array_map($this->airport(...), $airports);
    }

    /**
     * @return array{code: string, label: string}
     */
    public function airport(Airport $airport): array
    {
        return [
            'code' => $airport->iataCode,
            'label' => $airport->label(),
        ];
    }

    /**
     * @return array{count: int, cheapest: float|null}
     */
    public function totals(DealSummary $summary, DealType $type): array
    {
        return [
            'count' => $summary->countOf($type),
            'cheapest' => $summary->cheapestOf($type)?->toDecimal(),
        ];
    }

    /**
     * Both ends of a route in full, so a reader gets the parts rather than a
     * label it would have to take apart again.
     *
     * @return array{code: string, city: string, country: string|null}|null
     */
    private function endpoint(?Airport $airport): ?array
    {
        if ($airport === null) {
            return null;
        }

        return [
            'code' => $airport->iataCode,
            'city' => $airport->label(),
            'country' => $airport->countryName,
        ];
    }
}
