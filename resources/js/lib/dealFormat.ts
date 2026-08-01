import type { BoardType, DealType } from '@/types';

/**
 * Every price on the page is whole złoty: the cents of a 249 PLN flight are
 * noise next to the decision the number is there to support.
 */
const priceFormatters = new Map<string, Intl.NumberFormat>();

const priceFormatter = (currency: string): Intl.NumberFormat => {
    let formatter = priceFormatters.get(currency);

    if (formatter === undefined) {
        formatter = new Intl.NumberFormat('pl-PL', {
            style: 'currency',
            currency,
            maximumFractionDigits: 0,
        });

        priceFormatters.set(currency, formatter);
    }

    return formatter;
};

const dayFormatter = new Intl.DateTimeFormat('pl-PL', {
    day: 'numeric',
    month: 'short',
});

const dayWithYearFormatter = new Intl.DateTimeFormat('pl-PL', {
    day: 'numeric',
    month: 'short',
    year: 'numeric',
});

export const formatPrice = (value: number | null, currency: string): string =>
    value === null ? '—' : priceFormatter(currency).format(value);

export const formatDay = (value: string | null): string | null =>
    value === null ? null : dayFormatter.format(new Date(value));

export const formatDayWithYear = (value: string | null): string | null =>
    value === null ? null : dayWithYearFormatter.format(new Date(value));

/**
 * A range within one year says the year once; a single date keeps it, because
 * a flight nine months out is a different offer from one next week.
 */
export const formatDateRange = (
    from: string | null,
    to: string | null,
): string | null => {
    if (from === null) {
        return null;
    }

    if (to === null) {
        return formatDayWithYear(from);
    }

    const sameYear =
        new Date(from).getFullYear() === new Date(to).getFullYear();

    return `${sameYear ? formatDay(from) : formatDayWithYear(from)} – ${formatDayWithYear(to)}`;
};

export const boardLabels: Record<BoardType, string> = {
    all_inclusive: 'all inclusive',
    full_board: 'pełne wyżywienie',
    half_board: 'dwa posiłki',
    breakfast: 'ze śniadaniem',
    room_only: 'bez wyżywienia',
};

export const typeLabels: Record<DealType, string> = {
    round_trip: 'tam i z powrotem',
    flight: 'w jedną stronę',
    trip: 'wycieczka',
};

/**
 * What the price buys. The badge above already names the kind, so this says
 * what the number covers instead of repeating it.
 */
export const priceScopeLabels: Record<DealType, string> = {
    round_trip: 'za oba loty',
    flight: 'za lot',
    trip: 'za całość',
};

/**
 * Several adapters query the same airline, and the owner cares which airline it
 * is rather than which query found it.
 */
const sourceLabels: Record<string, string> = {
    ryanair: 'Ryanair',
    'ryanair-return': 'Ryanair',
    'ryanair-pairs': 'Ryanair',
    wizzair: 'Wizz Air',
    fly4free: 'Fly4free',
    'wakacyjni-piraci': 'Wakacyjni Piraci',
};

export const sourceLabel = (source: string): string =>
    sourceLabels[source] ?? source;

/**
 * Nights are what a stay is measured in; a one-way flight has neither.
 */
export const nightsLabel = (nights: number): string => {
    if (nights === 1) {
        return '1 noc';
    }

    const remainder = nights % 10;
    const teens = nights % 100;

    return remainder >= 2 && remainder <= 4 && (teens < 12 || teens > 14)
        ? `${nights} noce`
        : `${nights} nocy`;
};

export const daysLabel = (days: number): string =>
    days === 1 ? '1 dzień' : `${days} dni`;
