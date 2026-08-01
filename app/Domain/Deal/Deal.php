<?php

declare(strict_types=1);

namespace App\Domain\Deal;

use App\Domain\Deal\ValueObject\Airport;
use App\Domain\Deal\ValueObject\DealScore;
use App\Domain\Deal\ValueObject\Money;
use App\Domain\Deal\ValueObject\TripDetails;
use Carbon\CarbonImmutable;

/**
 * A single offer worth looking at, whatever it was found by.
 *
 * Flights carry a concrete route and a departure. Offers announced on blogs
 * carry a publication date and whatever the article gave up - length, board,
 * hotel standard. The two dates are kept apart on purpose: a departure in the
 * past means the offer is gone, a publication date in the past means nothing.
 */
final readonly class Deal
{
    private function __construct(
        public string $source,
        public DealType $type,
        public string $title,
        public Money $price,
        public string $url,
        public ?Airport $origin,
        public ?Airport $destination,
        public ?CarbonImmutable $departsAt,
        public ?CarbonImmutable $returnsAt,
        public ?CarbonImmutable $publishedAt,
        public ?TripDetails $trip,
        public ?DealScore $score,
        public bool $weekendGetaway = false,
        /** What this route normally costs, when we have seen enough of it. */
        public ?Money $typicalPrice = null,
    ) {}

    public static function flight(
        string $source,
        string $title,
        Money $price,
        string $url,
        Airport $origin,
        Airport $destination,
        ?CarbonImmutable $departsAt = null,
    ): self {
        return new self(
            $source,
            DealType::Flight,
            $title,
            $price,
            $url,
            $origin,
            $destination,
            $departsAt,
            null,
            null,
            null,
            null,
        );
    }

    /**
     * Both legs together. The price is what the whole thing costs and the stay
     * is what you would have to book off work - a 40 PLN seat with a return a
     * month later is not a holiday anyone can take.
     */
    public static function roundTrip(
        string $source,
        string $title,
        Money $totalPrice,
        string $url,
        Airport $origin,
        Airport $destination,
        CarbonImmutable $departsAt,
        CarbonImmutable $returnsAt,
    ): self {
        return new self(
            $source,
            DealType::RoundTrip,
            $title,
            $totalPrice,
            $url,
            $origin,
            $destination,
            $departsAt,
            $returnsAt,
            null,
            new TripDetails(days: max(1, (int) $departsAt->startOfDay()->diffInDays($returnsAt->startOfDay()))),
            null,
        );
    }

    /**
     * How long the stay is, for a round trip.
     */
    public function nights(): ?int
    {
        return $this->trip?->days;
    }

    /**
     * An offer announced somewhere - a blog post, a feed item. The kind of
     * offer is known, the route usually is not.
     */
    public static function fromFeed(
        string $source,
        DealType $type,
        string $title,
        Money $price,
        string $url,
        ?CarbonImmutable $publishedAt = null,
        ?TripDetails $trip = null,
        ?DealScore $score = null,
    ): self {
        return new self($source, $type, $title, $price, $url, null, null, null, null, $publishedAt, $trip, $score);
    }

    public function scoredWith(?DealScore $score): self
    {
        return $this->with(score: $score);
    }

    public function markedAsWeekendGetaway(bool $weekendGetaway): self
    {
        return $this->with(weekendGetaway: $weekendGetaway);
    }

    public function comparedAgainst(Money $typicalPrice): self
    {
        return $this->with(typicalPrice: $typicalPrice);
    }

    /**
     * How far below the usual price this is, as a fraction - 0.4 means it
     * costs 60% of what the route normally goes for.
     */
    public function discount(): ?float
    {
        if ($this->typicalPrice === null || $this->typicalPrice->minorUnits <= 0) {
            return null;
        }

        return max(0.0, 1 - ($this->price->minorUnits / $this->typicalPrice->minorUnits));
    }

    private function with(
        ?DealScore $score = null,
        ?bool $weekendGetaway = null,
        ?Money $typicalPrice = null,
    ): self {
        return new self(
            $this->source,
            $this->type,
            $this->title,
            $this->price,
            $this->url,
            $this->origin,
            $this->destination,
            $this->departsAt,
            $this->returnsAt,
            $this->publishedAt,
            $this->trip,
            $score ?? $this->score,
            $weekendGetaway ?? $this->weekendGetaway,
            $typicalPrice ?? $this->typicalPrice,
        );
    }

    /**
     * Identity for deduplication. A price change produces a different
     * fingerprint on purpose - a cheaper offer on the same route is news.
     *
     * The source is deliberately left out: the same seats on the same days at
     * the same price are one offer, however many of our queries turned it up.
     * Feed items carry their link instead, which already names its blog.
     */
    public function fingerprint(): string
    {
        return sha1(implode('|', [
            $this->type->value,
            $this->origin->iataCode ?? '',
            $this->destination->iataCode ?? '',
            $this->departsAt?->toDateString() ?? '',
            $this->returnsAt?->toDateString() ?? '',
            // Feed items have no route, so the link is what identifies them.
            $this->origin === null ? $this->url : '',
            (string) $this->price->minorUnits,
        ]));
    }

    public function routeLabel(): ?string
    {
        if ($this->origin === null || $this->destination === null) {
            return null;
        }

        return sprintf('%s → %s', $this->origin->iataCode, $this->destination->iataCode);
    }
}
