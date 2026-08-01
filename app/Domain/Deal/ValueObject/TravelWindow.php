<?php

declare(strict_types=1);

namespace App\Domain\Deal\ValueObject;

use Carbon\CarbonImmutable;

/**
 * A date an offer can be taken on, as a real date rather than the phrase the
 * article used - "31 sierpnia" says nothing until you can see it on a
 * calendar next to the others.
 *
 * The original wording is kept because it is what the blog will show if the
 * reader follows the link.
 */
final readonly class TravelWindow
{
    public function __construct(
        public CarbonImmutable $from,
        public ?CarbonImmutable $to,
        public string $label,
    ) {}

    /**
     * Every day the window covers.
     *
     * @return list<CarbonImmutable>
     */
    public function days(): array
    {
        $days = [$this->from];

        if ($this->to === null) {
            return $days;
        }

        for ($day = $this->from->addDay(); ! $day->greaterThan($this->to); $day = $day->addDay()) {
            $days[] = $day;
        }

        return $days;
    }
}
