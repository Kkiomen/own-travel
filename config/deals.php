<?php

declare(strict_types=1);
use App\Infrastructure\DealSource\Article\Fly4freeArticleParser;
use App\Infrastructure\DealSource\Article\WakacyjniPiraciArticleParser;

return [

    /*
    |--------------------------------------------------------------------------
    | Price gates
    |--------------------------------------------------------------------------
    |
    | A one-way flight at or below the flight threshold is worth an alert.
    | Packaged trips from blogs are judged separately - they are never going to
    | cost 150 PLN, but 2500 PLN for a week all-inclusive is a find.
    |
    */

    'currency' => 'PLN',

    'max_flight_price' => (float) env('DEAL_MAX_ONE_WAY_PLN', 300),

    'max_round_trip_price' => (float) env('DEAL_MAX_ROUND_TRIP_PLN', 600),

    'max_trip_price' => (float) env('DEAL_MAX_TRIP_PLN', 2500),

    /*
    |--------------------------------------------------------------------------
    | How long a trip may last
    |--------------------------------------------------------------------------
    |
    | A cheap seat out is worthless if the way back is a month later - nobody
    | gets that much leave. Both legs have to fit inside this window.
    |
    */

    'stay' => [
        'minimum_nights' => (int) env('DEAL_MIN_NIGHTS', 2),
        'maximum_nights' => (int) env('DEAL_MAX_NIGHTS', 10),
    ],

    /*
    |--------------------------------------------------------------------------
    | Trip scoring
    |--------------------------------------------------------------------------
    |
    | Everything lands on one 0-100 scale so flights and trips can share a
    | sorted list. Flights are judged on what they cost outright; trips on what
    | a day of them costs, because a headline price says nothing on its own -
    | 2500 PLN is a steal for ten days and a rip-off for three. A trip whose
    | length we could not read falls back to its total price.
    |
    | Everything within budget is kept and shown. The score only decides what
    | is good enough to be told about.
    |
    */

    'scoring' => [
        'flight' => [
            'great' => (float) env('DEAL_GREAT_FLIGHT_PLN', 100),
            'poor' => (float) env('DEAL_POOR_FLIGHT_PLN', 350),
        ],
        // Both legs together: a long weekend abroad for 250 PLN is the kind of
        // thing worth being told about.
        'round_trip' => [
            'great' => (float) env('DEAL_GREAT_ROUND_TRIP_PLN', 250),
            'poor' => (float) env('DEAL_POOR_ROUND_TRIP_PLN', 700),
        ],
        'trip_per_day' => [
            'great' => (float) env('DEAL_GREAT_PRICE_PER_DAY_PLN', 150),
            'poor' => (float) env('DEAL_POOR_PRICE_PER_DAY_PLN', 600),
        ],
        // Weekend getaways: 400 PLN for a couple of days is the kind of thing
        // worth being told about, 2500 is not.
        'trip_total' => [
            'great' => (float) env('DEAL_GREAT_TRIP_PLN', 400),
            'poor' => (float) env('DEAL_POOR_TRIP_PLN', 2500),
        ],
        'minimum_score' => (int) env('DEAL_MIN_SCORE', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | Article scraping
    |--------------------------------------------------------------------------
    |
    | Blog articles are opened to read the length, board and hotel standard
    | behind a headline price. A published offer does not change, so pages are
    | cached for a week.
    |
    */

    'articles' => [
        'cache_ttl' => (int) env('DEAL_ARTICLE_CACHE_TTL', 604800),
    ],

    /*
    |--------------------------------------------------------------------------
    | What to watch
    |--------------------------------------------------------------------------
    */

    'departure_airports' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('DEAL_DEPARTURE_AIRPORTS', 'KRK,WAW,KTW,WRO,GDN,POZ')),
    ))),

    'window_days' => (int) env('DEAL_WINDOW_DAYS', 90),

    // Home airport: its departures are listed before everything else.
    'preferred_origin' => (string) env('DEAL_PREFERRED_ORIGIN', 'WRO'),

    /*
    |--------------------------------------------------------------------------
    | What counts as a weekend
    |--------------------------------------------------------------------------
    |
    | Out on Friday afternoon or Saturday morning, back on Sunday - no leave
    | needed. The hours matter: a Friday flight at 06:00 costs a day off, and a
    | Saturday flight at 21:00 wastes the only full day.
    |
    */

    'weekend' => [
        'friday_from_hour' => (int) env('DEAL_WEEKEND_FRIDAY_FROM', 15),
        'saturday_until_hour' => (int) env('DEAL_WEEKEND_SATURDAY_UNTIL', 12),
    ],

    /*
    |--------------------------------------------------------------------------
    | What counts as a steal
    |--------------------------------------------------------------------------
    |
    | Cheap is relative to the route: 308 PLN to London is a good weekend, the
    | same money to Gdańsk is not. A scan prices a whole month of each route,
    | so an offer this far below what the route usually costs - and under the
    | ceiling - is flagged as a real promotion rather than merely cheap.
    |
    */

    'steal' => [
        'minimum_discount' => (float) env('DEAL_STEAL_DISCOUNT', 0.4),
        'ceiling' => (float) env('DEAL_STEAL_CEILING_PLN', 400),
    ],

    /*
    |--------------------------------------------------------------------------
    | Sources
    |--------------------------------------------------------------------------
    |
    | Every entry here becomes a DealSource adapter. Disable one and the scan
    | simply stops asking it.
    |
    */

    'http' => [
        'timeout' => (int) env('DEAL_HTTP_TIMEOUT', 20),
        'user_agent' => (string) env(
            'DEAL_USER_AGENT',
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36',
        ),
    ],

    'ryanair' => [
        'enabled' => (bool) env('DEAL_RYANAIR_ENABLED', true),
        'base_url' => 'https://services-api.ryanair.com',
        'booking_url' => 'https://www.ryanair.com/pl/pl/trip/flights/select',
        'market' => 'pl-pl',

        /*
        | Pairing the legs ourselves gives exact departure times and our own
        | rules, at the cost of a request per route per month per direction -
        | so it runs for the airports we actually fly from.
        */
        'pairing' => [
            'airports' => array_values(array_filter(array_map(
                'trim',
                explode(',', (string) env('DEAL_PAIRING_AIRPORTS', 'WRO,KTW,KRK,WAW,GDN,POZ')),
            ))),
            'routes_per_airport' => (int) env('DEAL_PAIRING_ROUTES', 10),
        ],
    ],

    'wizzair' => [
        'enabled' => (bool) env('DEAL_WIZZAIR_ENABLED', true),
        'api_url' => 'https://be.wizzair.com',
        'booking_url' => 'https://wizzair.com',
        'site_url' => 'https://wizzair.com/en-gb',
        // Bumped by Wizz Air every few weeks; discovered at runtime, this is
        // only the fallback when discovery fails.
        'fallback_version' => (string) env('DEAL_WIZZAIR_VERSION', '29.9.0'),
        'version_cache_ttl' => 86400,
        // The timetable endpoint needs an explicit route, so the ones worth
        // watching are listed here.
        'routes' => [
            'WAW' => ['LTN', 'BCN', 'MXP', 'LIS', 'CTA', 'AYT', 'TIA', 'NAP', 'MAD', 'CDG'],
            'KTW' => ['LTN', 'BCN', 'CTA', 'AYT', 'TIA'],
            'KRK' => ['LTN', 'CTA', 'TIA', 'MXP'],
            'GDN' => ['LTN', 'BCN', 'TIA'],
            'WRO' => ['LTN', 'CTA', 'TIA'],
            'POZ' => ['LTN', 'CTA'],
        ],
        // The endpoint rejects windows longer than roughly a month.
        'max_window_days' => 30,
    ],

    'feeds' => [
        // Each blog writes its offers up differently, so each gets the parser
        // that knows its markup. The ArticleParser port is what makes that a
        // one-line change rather than a branch inside a shared scraper.
        [
            'name' => 'fly4free',
            'url' => 'https://www.fly4free.pl/feed/',
            'parser' => Fly4freeArticleParser::class,
            'enabled' => true,
            // WordPress serves the archive behind the feed 20 posts at a time.
            // The front page alone is a day of posts - a handful of offers -
            // while a dozen pages reach about ten days back, comfortably inside
            // the retention window, and cost a dozen requests an hour.
            'pages' => (int) env('DEAL_FLY4FREE_PAGES', 12),
            'page_query' => 'paged',
        ],
        [
            'name' => 'wakacyjni-piraci',
            'url' => 'https://www.wakacyjnipiraci.pl/feed',
            'parser' => WakacyjniPiraciArticleParser::class,
            'enabled' => true,
            // Their feed answers with the same 28 offers whatever page is
            // asked for, so there is no archive to walk.
            'pages' => 1,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Dashboard
    |--------------------------------------------------------------------------
    */

    'dashboard_limit' => 60,

    /*
    |--------------------------------------------------------------------------
    | Retention
    |--------------------------------------------------------------------------
    |
    | Flights are dropped the moment they depart. Everything else is dropped
    | once it is this old - a blog offer from two months ago is not news.
    |
    */

    'retention_days' => (int) env('DEAL_RETENTION_DAYS', 45),

];
