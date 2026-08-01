<?php

declare(strict_types=1);

namespace App\Domain\Deal;

/**
 * What a score was worked out from - the whole price, or what a day of it
 * costs.
 */
enum ScoreBasis: string
{
    case TotalPrice = 'total_price';
    case PricePerDay = 'price_per_day';
}
