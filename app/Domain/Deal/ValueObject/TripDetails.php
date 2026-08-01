<?php

declare(strict_types=1);

namespace App\Domain\Deal\ValueObject;

use App\Domain\Deal\BoardType;
use InvalidArgumentException;

/**
 * What a blog post says about the trip behind a headline price.
 *
 * Only the length drives the score. The rest is there so the offer can be
 * judged without leaving the app - where it goes, how long, which airports it
 * leaves from, and on what dates.
 */
final readonly class TripDetails
{
    /**
     * @param  list<string>  $departureCities  where the trip can be flown from
     * @param  list<TravelWindow>  $dates  the dates the article names
     * @param  list<string>  $highlights  the offer's own bullet points
     */
    public function __construct(
        public ?int $days = null,
        public BoardType $board = BoardType::Unknown,
        public ?int $hotelStars = null,
        public ?string $destination = null,
        public ?string $hotel = null,
        public array $departureCities = [],
        public array $dates = [],
        public array $highlights = [],
    ) {
        if ($days !== null && $days < 1) {
            throw new InvalidArgumentException('A trip cannot be shorter than a day.');
        }

        if ($hotelStars !== null && ($hotelStars < 1 || $hotelStars > 5)) {
            throw new InvalidArgumentException('Hotel standard is expressed in one to five stars.');
        }
    }

    public static function unknown(): self
    {
        return new self;
    }

    /**
     * Without a length there is nothing to divide the price by, so the offer
     * cannot be scored.
     */
    public function areScorable(): bool
    {
        return $this->days !== null;
    }

    /**
     * Whether there is enough here to be worth opening.
     */
    public function areWorthShowing(): bool
    {
        return $this->destination !== null
            || $this->hotel !== null
            || $this->departureCities !== []
            || $this->dates !== []
            || $this->highlights !== [];
    }
}
