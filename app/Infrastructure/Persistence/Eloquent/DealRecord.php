<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Storage shape of a deal. Nothing outside the persistence adapter touches it.
 *
 * @property string $fingerprint
 * @property string $source
 * @property string $type
 * @property string $title
 * @property string $url
 * @property int $price_minor_units
 * @property string $price_currency
 * @property string|null $origin_iata
 * @property string|null $origin_name
 * @property string|null $destination_iata
 * @property string|null $destination_name
 * @property string|null $destination_country
 * @property CarbonImmutable|null $departs_at
 * @property CarbonImmutable|null $returns_at
 * @property CarbonImmutable|null $published_at
 * @property int|null $trip_days
 * @property string|null $board
 * @property int|null $hotel_stars
 * @property array<string, mixed>|null $trip_details
 * @property bool $weekend_getaway
 * @property int|null $typical_price_minor_units
 * @property bool $steal
 * @property int|null $score
 * @property int|null $rated_price_minor_units
 * @property string|null $score_basis
 * @property CarbonImmutable $found_at
 */
final class DealRecord extends Model
{
    protected $table = 'deals';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price_minor_units' => 'integer',
            'trip_days' => 'integer',
            'hotel_stars' => 'integer',
            'trip_details' => 'array',
            'score' => 'integer',
            'rated_price_minor_units' => 'integer',
            'weekend_getaway' => 'boolean',
            'typical_price_minor_units' => 'integer',
            'steal' => 'boolean',
            'departs_at' => 'immutable_datetime',
            'returns_at' => 'immutable_datetime',
            'published_at' => 'immutable_datetime',
            'found_at' => 'immutable_datetime',
        ];
    }
}
