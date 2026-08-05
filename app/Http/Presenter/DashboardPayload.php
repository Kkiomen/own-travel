<?php

declare(strict_types=1);

namespace App\Http\Presenter;

use App\Domain\Deal\DealType;
use App\Domain\Deal\Port\DealRepository;
use App\Domain\Deal\Service\DealEvaluator;
use App\Domain\Deal\ValueObject\DealListing;
use App\Http\Query\DealQuery;
use Illuminate\Http\Request;

/**
 * Everything one screenful of the board is made of.
 *
 * The dashboard renders this through Inertia and the API sends the same array
 * as JSON, so another app can rebuild the whole view from a single request and
 * cannot be looking at a different board from the one here. Anything added to
 * the screen belongs here, not in either controller.
 */
final readonly class DashboardPayload
{
    public function __construct(
        private DealRepository $deals,
        private DealEvaluator $evaluator,
        private DealPresenter $presenter,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function for(Request $request, int $limit): array
    {
        $query = DealQuery::fromRequest($request, $limit);
        $listing = $query->listing;

        $summary = $this->deals->summarise($listing->now);
        // The airport lists answer to the filters already chosen, so "Dokąd"
        // offers where this origin actually flies rather than every airport.
        $airports = $this->deals->availableAirports($listing);
        $undated = $query->undatedTrips();

        return [
            'deals' => $this->presenter->all($this->deals->list($listing)),
            ...$query->toArray(),
            'undated_trips' => $this->presenter->all(
                $undated instanceof DealListing ? $this->deals->list($undated) : [],
            ),
            'airports' => [
                'origins' => $this->presenter->airports($airports['origins']),
                'destinations' => $this->presenter->airports($airports['destinations']),
            ],
            'totals' => [
                'flight' => $this->presenter->totals($summary, DealType::Flight),
                'round_trip' => $this->presenter->totals($summary, DealType::RoundTrip),
                'trip' => $this->presenter->totals($summary, DealType::Trip),
            ],
            'thresholds' => $this->thresholds(),
            'currency' => (string) config('deals.currency', 'PLN'),
        ];
    }

    /**
     * The two gates: price decides what is kept and shown, score decides what
     * is worth being told about.
     *
     * @return array{flight: float, round_trip: float, trip: float, score: int}
     */
    public function thresholds(): array
    {
        return [
            'flight' => $this->evaluator->thresholdFor(DealType::Flight)->toDecimal(),
            'round_trip' => $this->evaluator->thresholdFor(DealType::RoundTrip)->toDecimal(),
            'trip' => $this->evaluator->thresholdFor(DealType::Trip)->toDecimal(),
            'score' => $this->evaluator->minimumTripScore(),
        ];
    }
}
