<?php

declare(strict_types=1);

namespace App\Infrastructure\DealSource\Parser;

use App\Domain\Deal\DealType;

/**
 * Feed items mix bare flights with packaged trips, and they are judged against
 * very different price thresholds - so the headline has to tell us which is
 * which.
 *
 * **Naming a flight is not enough to make it one.** Most packages sell the
 * flights as part of the deal ("Loty i 4* hotel za 579 PLN", "Wycieczka na
 * Korfu - w cenie loty + hotel"), and reading those as flights priced them
 * against the one-way gate, where every last one of them was thrown away. What
 * separates the two is the stay: a bed, a board, or a number of nights. So a
 * headline naming any of those is a trip however loudly it also advertises the
 * flights, and only a headline selling the seat alone is a flight.
 */
final readonly class OfferTypeClassifier
{
    private const FLIGHT_PATTERN = '/\b(lot|loty|lotu|lotów|przelot|przeloty|bilety lotnicze|flight)\w*/iu';

    /**
     * None of this can be had without somewhere to sleep, so any of it means
     * the price covers more than a seat.
     */
    private const STAY_PATTERN = '/('
        .'wycieczk\w*|wczas\w*|all\s*inclusive'
        .'|nocleg\w*|hotel\w*|apartament\w*|pobyt\w*'
        .'|śniadani\w*|sniadani\w*|wyżywieni\w*|wyzywieni\w*'
        .'|\d+\s*(noc\w*|dni|dzień|dzien)\b'
        .')/iu';

    public function classify(string $title): DealType
    {
        $sellsFlight = preg_match(self::FLIGHT_PATTERN, $title) === 1;
        $includesStay = preg_match(self::STAY_PATTERN, $title) === 1;

        return $sellsFlight && ! $includesStay
            ? DealType::Flight
            : DealType::Trip;
    }
}
