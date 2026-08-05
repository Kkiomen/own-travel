<?php

declare(strict_types=1);

namespace App\Http\OpenApi;

use App\Domain\Deal\BoardType;
use App\Domain\Deal\DealSort;
use App\Domain\Deal\DealType;

/**
 * The API described in OpenAPI 3.1, for Swagger UI and code generators.
 *
 * Written as PHP rather than a checked-in YAML file so the parts that already
 * exist in code - the sorts, the kinds of deal, the boards, the price gates -
 * are read from it. A hand-kept document drifts the moment an enum gains a
 * case; this one cannot.
 */
final readonly class DealApiSpecification
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'openapi' => '3.1.0',
            'info' => [
                'title' => config('app.name').' - deals API',
                'version' => '1.0.0',
                'description' => <<<'TEXT'
                    Read-only access to the travel deals this app finds: cheap one-way
                    flights, round trips priced as a whole, and packaged trips announced
                    on blogs.

                    There is no authentication - the app is single-owner and protected by
                    not exposing its port. Do not publish this API to the internet.

                    Every response carries numbers and codes, never formatted strings:
                    prices are decimals in the currency named by the response, dates are
                    ISO 8601, and durations are counts of days. Formatting is the
                    reader's business.
                    TEXT,
            ],
            'servers' => [
                ['url' => rtrim((string) config('app.url'), '/'), 'description' => 'This instance'],
            ],
            'tags' => [
                ['name' => 'Board', 'description' => 'The whole screen, for rebuilding the view elsewhere'],
                ['name' => 'Deals', 'description' => 'What is currently on offer'],
            ],
            'paths' => [
                '/api/v1/dashboard' => $this->dashboardPath(),
                '/api/v1/deals' => $this->dealsPath(),
                '/api/v1/deals/airports' => $this->airportsPath(),
                '/api/v1/deals/summary' => $this->summaryPath(),
                '/api/v1/deals/{deal}' => $this->dealPath(),
                '/api/v1/meta' => $this->metaPath(),
            ],
            'components' => [
                'schemas' => $this->schemas(),
                'parameters' => $this->parameters(),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function dashboardPath(): array
    {
        return [
            'get' => [
                'tags' => ['Board'],
                'operationId' => 'dashboard',
                'summary' => 'Everything one screenful of the board is made of',
                'description' => <<<'TEXT'
                    The payload the dashboard itself is rendered from, as JSON: the deals,
                    the undated trips, the filters that took effect, the airport facets,
                    the totals and the price gates. One request rebuilds the whole view.

                    Takes the same filters as `/api/v1/deals`.
                    TEXT,
                'parameters' => [
                    ['$ref' => '#/components/parameters/sort'],
                    ['$ref' => '#/components/parameters/type'],
                    ['$ref' => '#/components/parameters/weekends'],
                    ['$ref' => '#/components/parameters/steals'],
                    ['$ref' => '#/components/parameters/origin'],
                    ['$ref' => '#/components/parameters/destination'],
                    ['$ref' => '#/components/parameters/from'],
                    ['$ref' => '#/components/parameters/to'],
                    ['$ref' => '#/components/parameters/limit'],
                ],
                'responses' => [
                    '200' => [
                        'description' => 'The board',
                        'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Dashboard']]],
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function dealPath(): array
    {
        return [
            'get' => [
                'tags' => ['Deals'],
                'operationId' => 'showDeal',
                'summary' => 'One offer, by its id',
                'description' => 'For linking straight to a deal. A flight that has already departed is gone, and answers 404 rather than showing an offer nobody can take.',
                'parameters' => [[
                    'name' => 'deal',
                    'in' => 'path',
                    'required' => true,
                    'description' => 'The `id` from a listing.',
                    'schema' => ['type' => 'string'],
                ]],
                'responses' => [
                    '200' => [
                        'description' => 'The offer',
                        'content' => ['application/json' => ['schema' => [
                            'type' => 'object',
                            'required' => ['data'],
                            'properties' => ['data' => ['$ref' => '#/components/schemas/Deal']],
                        ]]],
                    ],
                    '404' => [
                        'description' => 'Gone, expired or never known',
                        'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Error']]],
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function metaPath(): array
    {
        return [
            'get' => [
                'tags' => ['Board'],
                'operationId' => 'meta',
                'summary' => 'Every value a filter accepts, and the bounds the app works within',
                'description' => 'Values only, never display copy - what a `round_trip` is called is the reading app\'s decision, and this one is written in Polish.',
                'responses' => [
                    '200' => [
                        'description' => 'The vocabulary',
                        'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Meta']]],
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function dealsPath(): array
    {
        return [
            'get' => [
                'tags' => ['Deals'],
                'operationId' => 'listDeals',
                'summary' => 'Deals worth showing, ordered and filtered as asked',
                'description' => <<<'TEXT'
                    Ordering and filtering happen in the database, so the response holds
                    the best matches rather than the first page of everything. Flights
                    that have already departed are never returned.

                    Anything unusable - an unknown sort, a mistyped IATA code, half a
                    date range - is treated as no filter at all rather than an error.
                    `meta.filters` echoes back what actually took effect.
                    TEXT,
                'parameters' => [
                    ['$ref' => '#/components/parameters/sort'],
                    ['$ref' => '#/components/parameters/type'],
                    ['$ref' => '#/components/parameters/weekends'],
                    ['$ref' => '#/components/parameters/steals'],
                    ['$ref' => '#/components/parameters/origin'],
                    ['$ref' => '#/components/parameters/destination'],
                    ['$ref' => '#/components/parameters/from'],
                    ['$ref' => '#/components/parameters/to'],
                    ['$ref' => '#/components/parameters/limit'],
                ],
                'responses' => [
                    '200' => [
                        'description' => 'The deals on offer',
                        'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/DealList']]],
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function airportsPath(): array
    {
        return [
            'get' => [
                'tags' => ['Deals'],
                'operationId' => 'listAirports',
                'summary' => 'The airports on offer under the filters already chosen',
                'description' => <<<'TEXT'
                    Facets, not a fixed inventory: pick an origin and the destinations are
                    where it actually flies. Each side ignores its own filter, so a list
                    never narrows to the one value already picked.

                    With `type=trip` both lists come back empty - blog offers carry no
                    IATA code.
                    TEXT,
                'parameters' => [
                    ['$ref' => '#/components/parameters/type'],
                    ['$ref' => '#/components/parameters/weekends'],
                    ['$ref' => '#/components/parameters/steals'],
                    ['$ref' => '#/components/parameters/origin'],
                    ['$ref' => '#/components/parameters/destination'],
                    ['$ref' => '#/components/parameters/from'],
                    ['$ref' => '#/components/parameters/to'],
                ],
                'responses' => [
                    '200' => [
                        'description' => 'Where there are deals to be had',
                        'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/AirportFacets']]],
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function summaryPath(): array
    {
        return [
            'get' => [
                'tags' => ['Deals'],
                'operationId' => 'dealSummary',
                'summary' => 'How much is on offer overall, and the gates it is judged by',
                'description' => 'Counted across everything kept, not over one page, so the figures stay true while a filter is applied.',
                'responses' => [
                    '200' => [
                        'description' => 'Totals and thresholds',
                        'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Summary']]],
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function parameters(): array
    {
        return [
            'sort' => [
                'name' => 'sort',
                'in' => 'query',
                'description' => 'How to order the result. Departures from the preferred origin are listed first whatever the sort.',
                'schema' => [
                    'type' => 'string',
                    'enum' => array_map(static fn (DealSort $sort): string => $sort->value, DealSort::cases()),
                    'default' => DealSort::Score->value,
                ],
            ],
            'type' => [
                'name' => 'type',
                'in' => 'query',
                'description' => 'Only this kind of deal. Omit for all of them.',
                'schema' => $this->dealTypeSchema(),
            ],
            'weekends' => [
                'name' => 'weekends',
                'in' => 'query',
                'description' => 'Only round trips that work as a weekend getaway: out Friday from 15:00 or Saturday before noon, back on Sunday, at most three nights.',
                'schema' => ['type' => 'boolean', 'default' => false],
            ],
            'steals' => [
                'name' => 'steals',
                'in' => 'query',
                'description' => 'Only offers far below what that route normally costs, and under the steal ceiling.',
                'schema' => ['type' => 'boolean', 'default' => false],
            ],
            'origin' => [
                'name' => 'origin',
                'in' => 'query',
                'description' => 'Departure airport, IATA code.',
                'schema' => ['type' => 'string', 'pattern' => '^[A-Za-z]{3}$'],
                'example' => 'WRO',
            ],
            'destination' => [
                'name' => 'destination',
                'in' => 'query',
                'description' => 'Arrival airport, IATA code.',
                'schema' => ['type' => 'string', 'pattern' => '^[A-Za-z]{3}$'],
                'example' => 'AGP',
            ],
            'from' => [
                'name' => 'from',
                'in' => 'query',
                'description' => <<<'TEXT'
                    First day of booked leave. Given together with `to`, only journeys
                    that fit *entirely* inside the window are returned - a round trip on
                    both its departure and its return, a one-way on its departure alone.
                    Bounds are whole days.

                    Half a range, or a backwards one, is no filter at all.
                    TEXT,
                'schema' => ['type' => 'string', 'format' => 'date'],
                'example' => '2026-09-12',
            ],
            'to' => [
                'name' => 'to',
                'in' => 'query',
                'description' => 'Last day of booked leave. See `from`.',
                'schema' => ['type' => 'string', 'format' => 'date'],
                'example' => '2026-09-20',
            ],
            'limit' => [
                'name' => 'limit',
                'in' => 'query',
                'description' => 'How many deals to return, at most 200.',
                'schema' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => 200,
                    'default' => (int) config('deals.dashboard_limit', 60),
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function schemas(): array
    {
        return [
            'Dashboard' => [
                'type' => 'object',
                'description' => 'The dashboard\'s own props. Every key here is what the page itself is drawn from.',
                'required' => ['deals', 'undated_trips', 'airports', 'totals', 'thresholds', 'currency'],
                'properties' => [
                    'deals' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Deal']],
                    'undated_trips' => [
                        'type' => 'array',
                        'description' => 'Blog offers whose article never named a term, sent only when a holiday window was asked for.',
                        'items' => ['$ref' => '#/components/schemas/Deal'],
                    ],
                    'sort' => ['type' => 'string', 'enum' => array_map(static fn (DealSort $sort): string => $sort->value, DealSort::cases())],
                    'type' => $this->nullable($this->dealTypeSchema()),
                    'weekends' => ['type' => 'boolean'],
                    'steals' => ['type' => 'boolean'],
                    'origin' => ['type' => ['string', 'null']],
                    'destination' => ['type' => ['string', 'null']],
                    'from' => ['type' => ['string', 'null'], 'format' => 'date'],
                    'to' => ['type' => ['string', 'null'], 'format' => 'date'],
                    'airports' => ['$ref' => '#/components/schemas/AirportFacets'],
                    'totals' => ['$ref' => '#/components/schemas/Totals'],
                    'thresholds' => ['$ref' => '#/components/schemas/Thresholds'],
                    'currency' => ['type' => 'string'],
                ],
            ],
            'Meta' => [
                'type' => 'object',
                'required' => ['sorts', 'types', 'boards', 'currency', 'thresholds', 'stay', 'preferred_origin', 'window_days', 'limits'],
                'properties' => [
                    'sorts' => ['type' => 'array', 'items' => ['type' => 'string']],
                    'types' => ['type' => 'array', 'items' => ['type' => 'string']],
                    'boards' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Boards a trip may carry. A deal sends null where the article never said.'],
                    'currency' => ['type' => 'string'],
                    'thresholds' => ['$ref' => '#/components/schemas/Thresholds'],
                    'stay' => [
                        'type' => 'object',
                        'description' => 'How long a round trip may last to count as leave anyone can take.',
                        'properties' => [
                            'minimum_nights' => ['type' => 'integer'],
                            'maximum_nights' => ['type' => 'integer'],
                        ],
                    ],
                    'preferred_origin' => ['type' => 'string', 'description' => 'The home airport, listed first whatever the sort.'],
                    'window_days' => ['type' => 'integer', 'description' => 'How far ahead deals are collected at all - a holiday further out than this finds nothing.'],
                    'limits' => [
                        'type' => 'object',
                        'properties' => [
                            'default' => ['type' => 'integer'],
                            'maximum' => ['type' => 'integer'],
                        ],
                    ],
                ],
            ],
            'Error' => [
                'type' => 'object',
                'properties' => ['message' => ['type' => 'string']],
            ],
            'DealList' => [
                'type' => 'object',
                'required' => ['data', 'undated_trips', 'meta'],
                'properties' => [
                    'data' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Deal']],
                    'undated_trips' => [
                        'type' => 'array',
                        'description' => <<<'TEXT'
                            Blog offers whose article never named a date, returned only when
                            a holiday window was asked for. They cannot be proven to fit -
                            or not to - so they are kept apart rather than claimed as
                            matches. Empty otherwise.
                            TEXT,
                        'items' => ['$ref' => '#/components/schemas/Deal'],
                    ],
                    'meta' => [
                        'type' => 'object',
                        'required' => ['count', 'limit', 'currency', 'filters', 'generated_at'],
                        'properties' => [
                            'count' => ['type' => 'integer', 'description' => 'How many deals are in `data`.'],
                            'limit' => ['type' => 'integer'],
                            'currency' => ['type' => 'string', 'example' => (string) config('deals.currency', 'PLN')],
                            'filters' => ['$ref' => '#/components/schemas/Filters'],
                            'generated_at' => ['type' => 'string', 'format' => 'date-time'],
                        ],
                    ],
                ],
            ],
            'Filters' => [
                'type' => 'object',
                'description' => 'What the request actually asked for, after unusable values were dropped.',
                'properties' => [
                    'sort' => ['type' => 'string', 'enum' => array_map(static fn (DealSort $sort): string => $sort->value, DealSort::cases())],
                    'type' => $this->nullable($this->dealTypeSchema()),
                    'weekends' => ['type' => 'boolean'],
                    'steals' => ['type' => 'boolean'],
                    'origin' => ['type' => ['string', 'null']],
                    'destination' => ['type' => ['string', 'null']],
                    'from' => ['type' => ['string', 'null'], 'format' => 'date'],
                    'to' => ['type' => ['string', 'null'], 'format' => 'date'],
                ],
            ],
            'Deal' => [
                'type' => 'object',
                'description' => 'A single offer. Flights carry a route and a departure; blog trips carry a publication date and whatever the article gave up.',
                'required' => ['id', 'source', 'type', 'title', 'price', 'currency', 'url'],
                'properties' => [
                    'id' => [
                        'type' => 'string',
                        'description' => 'Fingerprint: kind + route + dates + price. Stable while the offer is, and deliberately different once the price changes - a cheaper seat on a known route is a new deal.',
                    ],
                    'source' => ['type' => 'string', 'description' => 'Which search found it.', 'example' => 'ryanair-pairs'],
                    'type' => $this->dealTypeSchema(),
                    'title' => ['type' => 'string'],
                    'price' => ['type' => 'number', 'description' => 'Total price of the whole offer, both legs included for a round trip.'],
                    'currency' => ['type' => 'string', 'example' => 'PLN'],
                    'url' => ['type' => 'string', 'format' => 'uri', 'description' => 'Where to book it, or the article announcing it.'],
                    'origin' => $this->nullable(['$ref' => '#/components/schemas/Endpoint']),
                    'destination' => $this->nullable(['$ref' => '#/components/schemas/Endpoint']),
                    'departs_at' => ['type' => ['string', 'null'], 'format' => 'date-time', 'description' => 'Flights only. Never interchangeable with `published_at`.'],
                    'returns_at' => ['type' => ['string', 'null'], 'format' => 'date-time', 'description' => 'Round trips only.'],
                    'published_at' => ['type' => ['string', 'null'], 'format' => 'date-time', 'description' => 'Blog offers only: when the article appeared.'],
                    'weekend' => ['type' => 'boolean', 'description' => 'Decided when the deal was found, and stored - change the rule and known deals keep their verdict until re-found.'],
                    'steal' => ['type' => 'boolean', 'description' => 'Far enough below the usual price for this route, and under the ceiling.'],
                    'typical_price' => ['type' => ['number', 'null'], 'description' => 'The median total for this route, once at least five pairings have been priced.'],
                    'discount' => ['type' => ['integer', 'null'], 'description' => 'Percent below `typical_price`.'],
                    'days' => ['type' => ['integer', 'null'], 'description' => 'Nights of stay - from the two legs for a round trip, from the article for a blog trip.'],
                    'board' => [
                        'type' => ['string', 'null'],
                        'enum' => [...BoardType::known(), null],
                        'description' => 'Null when the article never said.',
                    ],
                    'hotel_stars' => ['type' => ['integer', 'null']],
                    'trip_destination' => ['type' => ['string', 'null'], 'description' => 'Where a blog trip goes, in the nominative.'],
                    'hotel' => ['type' => ['string', 'null']],
                    'departure_cities' => ['type' => 'array', 'items' => ['type' => 'string']],
                    'dates' => [
                        'type' => 'array',
                        'description' => 'The terms a blog article named. Several windows are alternatives, not one long stay.',
                        'items' => ['$ref' => '#/components/schemas/TravelWindow'],
                    ],
                    'highlights' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => "The offer's own bullet points."],
                    'has_details' => ['type' => 'boolean', 'description' => 'Whether there is enough beyond the headline to be worth opening.'],
                    'score' => ['type' => ['integer', 'null'], 'description' => '0-100. Flights rated on what they cost, trips on what a day of them costs.'],
                    'price_per_day' => ['type' => ['number', 'null']],
                ],
            ],
            'Endpoint' => [
                'type' => 'object',
                'required' => ['code', 'city'],
                'properties' => [
                    'code' => ['type' => 'string', 'example' => 'WRO'],
                    'city' => ['type' => 'string', 'description' => 'The airport name where known, otherwise the code again.', 'example' => 'Wrocław'],
                    'country' => ['type' => ['string', 'null'], 'example' => 'Polska'],
                ],
            ],
            'TravelWindow' => [
                'type' => 'object',
                'required' => ['from', 'label'],
                'properties' => [
                    'from' => ['type' => 'string', 'format' => 'date'],
                    'to' => ['type' => ['string', 'null'], 'format' => 'date'],
                    'label' => ['type' => 'string', 'description' => 'The wording the article used.', 'example' => '31 sierpnia - 7 września'],
                ],
            ],
            'AirportFacets' => [
                'type' => 'object',
                'required' => ['origins', 'destinations'],
                'properties' => [
                    'origins' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Airport']],
                    'destinations' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Airport']],
                ],
            ],
            'Airport' => [
                'type' => 'object',
                'required' => ['code', 'label'],
                'properties' => [
                    'code' => ['type' => 'string', 'example' => 'WRO'],
                    'label' => ['type' => 'string', 'example' => 'Wrocław'],
                ],
            ],
            'Summary' => [
                'type' => 'object',
                'required' => ['totals', 'thresholds', 'currency'],
                'properties' => [
                    'totals' => ['$ref' => '#/components/schemas/Totals'],
                    'thresholds' => ['$ref' => '#/components/schemas/Thresholds'],
                    'currency' => ['type' => 'string'],
                ],
            ],
            'Totals' => [
                'type' => 'object',
                'description' => 'How much is on offer of each kind, counted across everything kept.',
                'properties' => array_combine(
                    array_map(static fn (DealType $type): string => $type->value, DealType::cases()),
                    array_fill(0, count(DealType::cases()), ['$ref' => '#/components/schemas/Total']),
                ),
            ],
            'Thresholds' => [
                'type' => 'object',
                'description' => 'The two gates: price decides what is kept and shown, score decides what is worth an alert.',
                'properties' => [
                    'flight' => ['type' => 'number'],
                    'round_trip' => ['type' => 'number'],
                    'trip' => ['type' => 'number'],
                    'score' => ['type' => 'integer'],
                ],
            ],
            'Total' => [
                'type' => 'object',
                'required' => ['count', 'cheapest'],
                'properties' => [
                    'count' => ['type' => 'integer'],
                    'cheapest' => ['type' => ['number', 'null']],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function dealTypeSchema(): array
    {
        return [
            'type' => 'string',
            'enum' => array_map(static fn (DealType $type): string => $type->value, DealType::cases()),
            'description' => 'flight - a single leg; round_trip - both legs priced together; trip - a package announced on a blog.',
        ];
    }

    /**
     * A `$ref` cannot be made nullable in place, so it is wrapped.
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    private function nullable(array $schema): array
    {
        return ['oneOf' => [$schema, ['type' => 'null']]];
    }
}
