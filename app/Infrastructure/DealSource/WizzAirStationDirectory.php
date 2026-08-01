<?php

declare(strict_types=1);

namespace App\Infrastructure\DealSource;

use App\Domain\Deal\ValueObject\Airport;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\Factory as HttpClient;
use Throwable;

/**
 * Puts a name to the airport codes Wizz Air's timetable answers with.
 *
 * The timetable itself says only "LTN", so every Wizz Air offer on the board
 * read as a bare code next to Ryanair's "Londyn-Luton". The names are public:
 * the same backend serves its whole station list, in Polish, and it barely
 * changes - so it is fetched once a week and looked up from memory after that.
 *
 * A directory that cannot be reached is not an outage: the offer is still worth
 * showing, just under its code.
 */
final readonly class WizzAirStationDirectory
{
    private const CACHE_KEY = 'wizzair.stations';

    public function __construct(
        private HttpClient $http,
        private Cache $cache,
        private WizzAirApiVersionResolver $versionResolver,
        private string $apiUrl,
        private string $language,
        private int $timeoutSeconds,
        private int $cacheTtlSeconds,
    ) {}

    public function lookUp(string $iataCode): Airport
    {
        $station = $this->stations()[strtoupper(trim($iataCode))] ?? null;

        if ($station === null) {
            return Airport::fromIataCode($iataCode);
        }

        return Airport::fromIataCode($iataCode, $station['name'], $station['country']);
    }

    /**
     * @return array<string, array{name: string|null, country: string|null}>
     */
    private function stations(): array
    {
        /** @var array<string, array{name: string|null, country: string|null}>|null $cached */
        $cached = $this->cache->get(self::CACHE_KEY);

        if (is_array($cached)) {
            return $cached;
        }

        $stations = $this->download();

        // An empty answer is cached too, so a directory that is down does not
        // cost a request per route for the rest of the day.
        $this->cache->put(self::CACHE_KEY, $stations, $this->cacheTtlSeconds);

        return $stations;
    }

    /**
     * @return array<string, array{name: string|null, country: string|null}>
     */
    private function download(): array
    {
        try {
            $response = $this->http
                ->timeout($this->timeoutSeconds)
                ->get(sprintf('%s/%s/Api/asset/map', $this->apiUrl, $this->versionResolver->resolve()), [
                    'languageCode' => $this->language,
                ]);
        } catch (Throwable) {
            return [];
        }

        if ($response->failed()) {
            return [];
        }

        $cities = $response->json('cities');

        if (! is_array($cities)) {
            return [];
        }

        $stations = [];

        foreach ($cities as $city) {
            if (! is_array($city) || ! is_string($city['iata'] ?? null)) {
                continue;
            }

            $stations[strtoupper($city['iata'])] = [
                // Some entries carry a trailing line break - "Kraków\r\n".
                'name' => $this->text($city['shortName'] ?? null),
                'country' => $this->text($city['countryName'] ?? null),
            ];
        }

        return $stations;
    }

    private function text(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
