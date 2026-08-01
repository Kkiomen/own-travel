<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { ArrowRightLeft, Plane, Umbrella } from '@lucide/vue';
import { computed, ref } from 'vue';
import DealCard from '@/components/deals/DealCard.vue';
import DealDetails from '@/components/deals/DealDetails.vue';
import EmptyState from '@/components/deals/EmptyState.vue';
import FilterBar from '@/components/deals/FilterBar.vue';
import StatTile from '@/components/deals/StatTile.vue';
import { formatPrice } from '@/lib/dealFormat';
import { dashboard } from '@/routes';
import type {
    AirportOptions,
    Deal,
    DealFilters,
    DealSort,
    DealThresholds,
    DealTotals,
    DealType,
} from '@/types';

const props = defineProps<{
    deals: Deal[];
    /** Blog offers whose article never named a term - only sent with a holiday. */
    undated_trips: Deal[];
    sort: DealSort;
    type: DealType | null;
    weekends: boolean;
    steals: boolean;
    origin: string | null;
    destination: string | null;
    from: string | null;
    to: string | null;
    airports: AirportOptions;
    totals: DealTotals;
    thresholds: DealThresholds;
    currency: string;
}>();

const filters = computed<DealFilters>(() => ({
    sort: props.sort,
    type: props.type ?? 'all',
    weekends: props.weekends,
    steals: props.steals,
    origin: props.origin ?? '',
    destination: props.destination ?? '',
    from: props.from ?? '',
    to: props.to ?? '',
}));

const holiday = computed(() =>
    props.from === null || props.to === null
        ? null
        : { from: props.from, to: props.to },
);

const holidayLabel = computed(() => {
    if (holiday.value === null) {
        return '';
    }

    const day = (iso: string) =>
        new Date(iso).toLocaleDateString('pl-PL', {
            day: 'numeric',
            month: 'long',
        });

    return `${day(holiday.value.from)} – ${day(holiday.value.to)}`;
});

/** The deal whose details are open, if any. */
const openDeal = ref<Deal | null>(null);

const isFiltered = computed(
    () =>
        filters.value.type !== 'all' ||
        filters.value.weekends ||
        filters.value.steals ||
        filters.value.origin !== '' ||
        filters.value.destination !== '' ||
        holiday.value !== null,
);

/**
 * Hundreds of deals are kept and only a page of them is sent, so both the
 * ordering and the filtering have to happen in the query - doing either here
 * would rank or hide the wrong ones.
 */
const apply = (changes: Partial<DealFilters>) => {
    const next = { ...filters.value, ...changes };

    router.get(
        dashboard().url,
        {
            sort: next.sort,
            type: next.type === 'all' ? undefined : next.type,
            weekends: next.weekends ? 1 : undefined,
            steals: next.steals ? 1 : undefined,
            origin: next.origin === '' ? undefined : next.origin,
            destination: next.destination === '' ? undefined : next.destination,
            // One end alone narrows nothing, so it is not worth a round trip.
            from: next.from !== '' && next.to !== '' ? next.from : undefined,
            to: next.from !== '' && next.to !== '' ? next.to : undefined,
        },
        { preserveState: true, preserveScroll: true, replace: true },
    );
};

const clearFilters = () =>
    apply({
        type: 'all',
        weekends: false,
        steals: false,
        origin: '',
        destination: '',
        from: '',
        to: '',
    });

const tiles = computed(() => [
    {
        key: 'round_trip' as const,
        label: 'Tam i z powrotem',
        icon: ArrowRightLeft,
        threshold: props.thresholds.round_trip,
    },
    {
        key: 'flight' as const,
        label: 'W jedną stronę',
        icon: Plane,
        threshold: props.thresholds.flight,
    },
    {
        key: 'trip' as const,
        label: 'Wycieczki',
        icon: Umbrella,
        threshold: props.thresholds.trip,
    },
]);

const trackedCount = computed(
    () =>
        props.totals.round_trip.count +
        props.totals.flight.count +
        props.totals.trip.count,
);

/**
 * The one number the page leads with: what the cheapest way out of here costs
 * right now, whatever kind of offer it turns out to be.
 */
const cheapestOverall = computed(() => {
    const prices = [
        props.totals.round_trip.cheapest,
        props.totals.flight.cheapest,
        props.totals.trip.cheapest,
    ].filter((price): price is number => price !== null);

    return prices.length === 0 ? null : Math.min(...prices);
});
</script>

<template>
    <Head title="Okazje" />

    <div class="mx-auto w-full max-w-[100rem] px-4 sm:px-6 lg:px-8">
        <section class="grid gap-4 py-8 lg:grid-cols-[1fr_2fr] lg:gap-6">
            <div
                class="surface flex flex-col justify-center overflow-hidden bg-primary px-6 py-7 text-primary-foreground"
            >
                <p class="text-sm text-primary-foreground/75">
                    Najtańszy wyjazd w tej chwili
                </p>
                <p
                    class="mt-2 text-5xl leading-none font-semibold tracking-tight"
                >
                    {{
                        cheapestOverall === null
                            ? 'brak'
                            : formatPrice(cheapestOverall, currency)
                    }}
                </p>
                <p class="mt-3 text-sm text-primary-foreground/75">
                    <template v-if="trackedCount === 0">
                        Radar nie znalazł jeszcze żadnej oferty w budżecie.
                    </template>
                    <template v-else>
                        {{ trackedCount }} ofert w budżecie · alert od
                        {{ thresholds.score }} pkt
                    </template>
                </p>
            </div>

            <div class="grid gap-4 sm:grid-cols-3">
                <StatTile
                    v-for="tile in tiles"
                    :key="tile.key"
                    :label="tile.label"
                    :icon="tile.icon"
                    :count="totals[tile.key].count"
                    :cheapest="totals[tile.key].cheapest"
                    :threshold="tile.threshold"
                    :currency="currency"
                />
            </div>
        </section>

        <FilterBar
            :filters="filters"
            :airports="airports"
            @change="apply($event)"
        />

        <section class="py-6">
            <h2
                v-if="holiday !== null"
                class="mb-4 text-sm font-medium text-muted-foreground"
            >
                Mieszczą się w urlopie
                <span class="text-foreground">{{ holidayLabel }}</span>
            </h2>

            <EmptyState
                v-if="deals.length === 0"
                :filtered="isFiltered"
                @clear="clearFilters()"
            />

            <ul
                v-else
                class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4"
            >
                <li v-for="deal in deals" :key="deal.id" class="flex">
                    <DealCard
                        :deal="deal"
                        :score-threshold="thresholds.score"
                        class="w-full"
                        @open="openDeal = deal"
                    />
                </li>
            </ul>
        </section>

        <!--
            Kept apart on purpose: most blog articles never name their terms in
            the summary the parser is allowed to read, so hiding these would
            bury the majority of the trips - but listing them above would claim
            a match nobody can stand behind.
        -->
        <section
            v-if="undated_trips.length > 0"
            class="border-t border-border/70 py-6"
        >
            <h2 class="text-sm font-medium text-muted-foreground">
                Termin nieznany
                <span class="text-foreground/70"
                    >– sprawdź na stronie oferty</span
                >
            </h2>
            <p class="mt-1 mb-4 text-sm text-muted-foreground">
                Te wycieczki nie podają terminu w zapowiedzi, więc nie da się
                stwierdzić, czy pasują do Twojego urlopu.
            </p>

            <ul
                class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4"
            >
                <li v-for="deal in undated_trips" :key="deal.id" class="flex">
                    <DealCard
                        :deal="deal"
                        :score-threshold="thresholds.score"
                        class="w-full"
                        @open="openDeal = deal"
                    />
                </li>
            </ul>
        </section>

        <DealDetails
            v-if="openDeal"
            :deal="openDeal"
            @close="openDeal = null"
        />
    </div>
</template>
