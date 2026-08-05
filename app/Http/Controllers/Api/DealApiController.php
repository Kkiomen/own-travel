<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Deal\BoardType;
use App\Domain\Deal\DealSort;
use App\Domain\Deal\DealType;
use App\Domain\Deal\Port\DealRepository;
use App\Domain\Deal\ValueObject\DealListing;
use App\Http\Controllers\Controller;
use App\Http\Presenter\DashboardPayload;
use App\Http\Presenter\DealPresenter;
use App\Http\Query\DealQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The dashboard's data, for anything that is not the dashboard.
 *
 * Same repository, same query reading, same presenter, and for the whole board
 * the same payload the page itself is rendered from - so an offer reads
 * identically wherever it is shown. The only thing this layer decides is how
 * many deals one request may ask for.
 */
final class DealApiController extends Controller
{
    /** More than this in one request is a scan, not a page. */
    private const MAX_LIMIT = 200;

    public function __construct(
        private readonly DealPresenter $presenter,
        private readonly DashboardPayload $board,
    ) {}

    /**
     * The whole screen in one request: deals, undated trips, the filters that
     * took effect, the airport facets, the totals and the gates. Everything
     * needed to rebuild the view elsewhere without three round trips.
     */
    public function dashboard(Request $request): JsonResponse
    {
        return response()->json($this->board->for($request, $this->limit($request)));
    }

    public function index(Request $request, DealRepository $deals): JsonResponse
    {
        $query = DealQuery::fromRequest($request, $this->limit($request));
        $undated = $query->undatedTrips();

        $found = $this->presenter->all($deals->list($query->listing));

        return response()->json([
            'data' => $found,
            'undated_trips' => $this->presenter->all(
                $undated instanceof DealListing ? $deals->list($undated) : [],
            ),
            'meta' => [
                'count' => count($found),
                'limit' => $query->listing->limit,
                'currency' => (string) config('deals.currency', 'PLN'),
                'filters' => $query->toArray(),
                'generated_at' => $query->listing->now->toIso8601String(),
            ],
        ]);
    }

    /**
     * One offer, for a link straight to it. A flight that has departed is gone
     * rather than shown - the same rule the listing applies.
     */
    public function show(string $deal, DealRepository $deals): JsonResponse
    {
        $found = $deals->find($deal, now()->toImmutable());

        abort_if($found === null, 404, 'No such deal is on offer.');

        return response()->json(['data' => $this->presenter->deal($found)]);
    }

    /**
     * The airports actually on offer under the filters already chosen, so a
     * client can build the same self-narrowing controls the dashboard has.
     */
    public function airports(Request $request, DealRepository $deals): JsonResponse
    {
        $query = DealQuery::fromRequest($request, $this->limit($request));
        $airports = $deals->availableAirports($query->listing);

        return response()->json([
            'origins' => $this->presenter->airports($airports['origins']),
            'destinations' => $this->presenter->airports($airports['destinations']),
        ]);
    }

    /**
     * How much is on offer overall, and the gates it is judged by. Counted in
     * the database, so it keeps telling the truth while a filter is applied.
     */
    public function summary(DealRepository $deals): JsonResponse
    {
        $summary = $deals->summarise(now()->toImmutable());

        return response()->json([
            'totals' => [
                'flight' => $this->presenter->totals($summary, DealType::Flight),
                'round_trip' => $this->presenter->totals($summary, DealType::RoundTrip),
                'trip' => $this->presenter->totals($summary, DealType::Trip),
            ],
            'thresholds' => $this->board->thresholds(),
            'currency' => (string) config('deals.currency', 'PLN'),
        ]);
    }

    /**
     * The vocabulary a client needs to build the controls: every value a filter
     * accepts, and the bounds the app itself works within.
     *
     * Values only, never display copy - what a `round_trip` is called is the
     * reading app's decision, and this one is written in Polish.
     */
    public function meta(): JsonResponse
    {
        return response()->json([
            'sorts' => array_map(static fn (DealSort $sort): string => $sort->value, DealSort::cases()),
            'types' => array_map(static fn (DealType $type): string => $type->value, DealType::cases()),
            'boards' => BoardType::known(),
            'currency' => (string) config('deals.currency', 'PLN'),
            'thresholds' => $this->board->thresholds(),
            'stay' => [
                'minimum_nights' => (int) config('deals.stay.minimum_nights'),
                'maximum_nights' => (int) config('deals.stay.maximum_nights'),
            ],
            'preferred_origin' => (string) config('deals.preferred_origin'),
            'window_days' => (int) config('deals.window_days'),
            'limits' => [
                'default' => (int) config('deals.dashboard_limit', 60),
                'maximum' => self::MAX_LIMIT,
            ],
        ]);
    }

    /**
     * An unusable limit is no limit at all, exactly like an unusable filter.
     */
    private function limit(Request $request): int
    {
        $asked = (int) $request->query('limit', (string) config('deals.dashboard_limit', 60));

        return max(1, min(self::MAX_LIMIT, $asked > 0 ? $asked : (int) config('deals.dashboard_limit', 60)));
    }
}
