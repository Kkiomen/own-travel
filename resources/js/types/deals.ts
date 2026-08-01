export type DealType = 'flight' | 'round_trip' | 'trip';

export type BoardType =
    'all_inclusive' | 'full_board' | 'half_board' | 'breakfast' | 'room_only';

/** A date an offer runs on, resolved to real days so it can be drawn. */
export type TravelWindow = {
    from: string;
    to: string | null;
    label: string;
};

export type DealEndpoint = {
    code: string;
    city: string;
    country: string | null;
};

export type Deal = {
    id: string;
    source: string;
    type: DealType;
    title: string;
    price: number;
    currency: string;
    url: string;
    origin: DealEndpoint | null;
    destination: DealEndpoint | null;
    departs_at: string | null;
    returns_at: string | null;
    published_at: string | null;
    weekend: boolean;
    steal: boolean;
    typical_price: number | null;
    discount: number | null;
    days: number | null;
    board: BoardType | null;
    hotel_stars: number | null;
    trip_destination: string | null;
    hotel: string | null;
    departure_cities: string[];
    dates: TravelWindow[];
    highlights: string[];
    has_details: boolean;
    score: number | null;
    price_per_day: number | null;
};

export type DealSort = 'newest' | 'score' | 'price';

export type DealTypeFilter = DealType | 'all';

/**
 * What the listing is narrowed to. Every one of these is resolved by the query,
 * never on the page - only a slice of the deals is ever sent, so filtering here
 * would hide the wrong ones.
 */
export type DealFilters = {
    sort: DealSort;
    type: DealTypeFilter;
    weekends: boolean;
    steals: boolean;
    origin: string;
    destination: string;
    /** Booked leave, as ISO dates. Both ends or neither - one alone filters nothing. */
    from: string;
    to: string;
};

export type AirportOption = {
    code: string;
    label: string;
};

export type AirportOptions = {
    origins: AirportOption[];
    destinations: AirportOption[];
};

export type DealTotal = {
    count: number;
    cheapest: number | null;
};

export type DealTotals = Record<DealType, DealTotal>;

export type DealThresholds = {
    flight: number;
    round_trip: number;
    trip: number;
    score: number;
};
