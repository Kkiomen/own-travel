<script setup lang="ts">
import {
    ArrowUpRight,
    MapPin,
    MoonStar,
    PlaneTakeoff,
    Star,
    UtensilsCrossed,
    X,
} from '@lucide/vue';
import { computed } from 'vue';
import DealCalendar from '@/components/deals/DealCalendar.vue';
import {
    boardLabels,
    daysLabel,
    formatDayWithYear,
    formatPrice,
    nightsLabel,
    priceScopeLabels,
    sourceLabel,
    typeLabels,
} from '@/lib/dealFormat';
import type { Deal } from '@/types';

const props = defineProps<{ deal: Deal }>();

const emit = defineEmits<{ close: [] }>();

/**
 * Where the trip goes: the airline knows the airport, a blog only its own
 * wording for the place.
 */
const destination = computed(
    () => props.deal.destination?.city ?? props.deal.trip_destination,
);

const duration = computed(() => {
    if (props.deal.days === null) {
        return null;
    }

    return props.deal.type === 'round_trip'
        ? nightsLabel(props.deal.days)
        : daysLabel(props.deal.days);
});

/** Nothing to draw unless the offer names a date or flies on one. */
const hasCalendar = computed(
    () =>
        props.deal.dates.length > 0 ||
        props.deal.departs_at !== null ||
        props.deal.returns_at !== null,
);

const priceCaption = computed(() => {
    if (props.deal.price_per_day !== null) {
        return `${formatPrice(props.deal.price_per_day, props.deal.currency)} / dzień`;
    }

    if (props.deal.typical_price !== null) {
        return `zwykle ${formatPrice(props.deal.typical_price, props.deal.currency)}`;
    }

    return priceScopeLabels[props.deal.type];
});
</script>

<template>
    <div
        class="fixed inset-0 z-50 flex items-end justify-center bg-black/60 backdrop-blur-sm sm:items-center sm:p-6"
        role="dialog"
        aria-modal="true"
        @click.self="emit('close')"
    >
        <div
            class="surface max-h-[92vh] w-full max-w-2xl overflow-y-auto rounded-t-2xl sm:rounded-2xl"
        >
            <div class="flex items-start justify-between gap-4 p-6 pb-4">
                <div class="min-w-0 space-y-2">
                    <div class="flex flex-wrap items-center gap-1.5">
                        <span
                            class="rounded-md bg-secondary px-2 py-0.5 text-xs font-medium text-secondary-foreground"
                        >
                            {{ sourceLabel(deal.source) }}
                        </span>
                        <span
                            class="rounded-md border border-border px-2 py-0.5 text-xs text-muted-foreground"
                        >
                            {{ typeLabels[deal.type] }}
                        </span>
                        <span
                            v-if="deal.weekend"
                            class="rounded-md bg-warn-soft px-2 py-0.5 text-xs font-medium text-warn-foreground"
                        >
                            weekend
                        </span>
                        <span
                            v-if="deal.steal"
                            class="rounded-md bg-good px-2 py-0.5 text-xs font-semibold text-white"
                        >
                            okazja −{{ deal.discount }}%
                        </span>
                    </div>

                    <h2 class="text-lg leading-snug font-semibold text-balance">
                        {{ deal.title }}
                    </h2>
                </div>

                <button
                    type="button"
                    class="rounded-lg p-1.5 text-muted-foreground transition-colors hover:bg-muted"
                    aria-label="Zamknij"
                    @click="emit('close')"
                >
                    <X class="size-5" />
                </button>
            </div>

            <dl class="grid gap-4 px-6 sm:grid-cols-2">
                <div v-if="destination">
                    <dt class="text-xs text-muted-foreground">Dokąd</dt>
                    <dd class="mt-1 flex items-center gap-2 font-medium">
                        <MapPin class="size-4 shrink-0 text-muted-foreground" />
                        <span class="min-w-0 truncate">
                            {{ destination }}
                            <span
                                v-if="deal.destination?.country"
                                class="text-muted-foreground"
                            >
                                · {{ deal.destination.country }}
                            </span>
                        </span>
                    </dd>
                </div>

                <div v-if="duration">
                    <dt class="text-xs text-muted-foreground">Jak długo</dt>
                    <dd class="mt-1 flex items-center gap-2 font-medium">
                        <MoonStar
                            class="size-4 shrink-0 text-muted-foreground"
                        />
                        {{ duration }}
                    </dd>
                </div>

                <div v-if="deal.hotel">
                    <dt class="text-xs text-muted-foreground">Hotel</dt>
                    <dd class="mt-1 flex items-center gap-2 font-medium">
                        <span class="min-w-0 truncate">{{ deal.hotel }}</span>
                        <span
                            v-if="deal.hotel_stars"
                            class="flex shrink-0 items-center gap-0.5 text-muted-foreground"
                        >
                            <Star class="size-3" />{{ deal.hotel_stars }}
                        </span>
                    </dd>
                </div>

                <div v-if="deal.board">
                    <dt class="text-xs text-muted-foreground">Wyżywienie</dt>
                    <dd class="mt-1 flex items-center gap-2 font-medium">
                        <UtensilsCrossed
                            class="size-4 shrink-0 text-muted-foreground"
                        />
                        {{ boardLabels[deal.board] }}
                    </dd>
                </div>

                <div v-if="deal.departure_cities.length > 0">
                    <dt class="text-xs text-muted-foreground">Wylot z</dt>
                    <dd class="mt-1 flex flex-wrap items-center gap-1.5">
                        <PlaneTakeoff
                            class="size-4 shrink-0 text-muted-foreground"
                        />
                        <span
                            v-for="city in deal.departure_cities"
                            :key="city"
                            class="rounded-md border border-border px-2 py-0.5 text-sm"
                        >
                            {{ city }}
                        </span>
                    </dd>
                </div>

                <div v-if="deal.origin">
                    <dt class="text-xs text-muted-foreground">Trasa</dt>
                    <dd class="mt-1 font-medium tabular-nums">
                        {{ deal.origin.code }} → {{ deal.destination?.code }}
                    </dd>
                </div>

                <div v-if="formatDayWithYear(deal.departs_at)">
                    <dt class="text-xs text-muted-foreground">Wylot</dt>
                    <dd class="mt-1 font-medium">
                        {{ formatDayWithYear(deal.departs_at) }}
                    </dd>
                </div>

                <div v-if="formatDayWithYear(deal.returns_at)">
                    <dt class="text-xs text-muted-foreground">Powrót</dt>
                    <dd class="mt-1 font-medium">
                        {{ formatDayWithYear(deal.returns_at) }}
                    </dd>
                </div>
            </dl>

            <div v-if="hasCalendar" class="px-6 pt-6">
                <p class="text-xs text-muted-foreground">Kiedy</p>
                <div class="mt-2">
                    <DealCalendar
                        :windows="deal.dates"
                        :departs-at="deal.departs_at"
                        :returns-at="deal.returns_at"
                    />
                </div>
                <p
                    v-if="deal.dates.length > 0"
                    class="mt-2 text-xs text-muted-foreground"
                >
                    Terminy podane w ofercie:
                    {{ deal.dates.map((date) => date.label).join(' · ') }}
                </p>
            </div>

            <div v-if="deal.highlights.length > 0" class="px-6 pt-6">
                <p class="text-xs text-muted-foreground">Co obejmuje oferta</p>
                <ul class="mt-2 space-y-1.5 text-sm">
                    <li
                        v-for="highlight in deal.highlights"
                        :key="highlight"
                        class="flex gap-2"
                    >
                        <span class="text-muted-foreground">•</span>
                        <span>{{ highlight }}</span>
                    </li>
                </ul>
            </div>

            <div
                class="mt-6 flex items-end justify-between gap-4 border-t border-border p-6"
            >
                <div>
                    <p class="text-2xl font-semibold tabular-nums">
                        {{ formatPrice(deal.price, deal.currency) }}
                    </p>
                    <p class="text-xs text-muted-foreground">
                        {{ priceCaption }}
                    </p>
                </div>

                <a
                    :href="deal.url"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="inline-flex items-center gap-2 rounded-lg bg-primary px-4 py-2 text-sm font-medium text-primary-foreground transition-opacity hover:opacity-90"
                >
                    Przejdź do oferty
                    <ArrowUpRight class="size-4" />
                </a>
            </div>
        </div>
    </div>
</template>
