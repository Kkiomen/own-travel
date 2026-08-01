<script setup lang="ts">
import {
    ArrowRightLeft,
    ArrowUpRight,
    CalendarDays,
    MoonStar,
    Plane,
    Star,
    TrendingDown,
    UtensilsCrossed,
} from '@lucide/vue';
import { computed } from 'vue';
import ScoreRing from '@/components/deals/ScoreRing.vue';
import {
    boardLabels,
    daysLabel,
    formatDateRange,
    formatPrice,
    nightsLabel,
    priceScopeLabels,
    sourceLabel,
    typeLabels,
} from '@/lib/dealFormat';
import type { Deal } from '@/types';

const props = defineProps<{
    deal: Deal;
    /** The score from which an offer is worth an alert. */
    scoreThreshold: number;
}>();

const emit = defineEmits<{ open: [] }>();

/**
 * A flight knows both ends of its route; a blog offer only has a headline.
 * Not every source names the airport, and a city line repeating the IATA code
 * says nothing, so it is left out.
 */
const route = computed(() => {
    const { origin, destination } = props.deal;

    return origin === null || destination === null
        ? null
        : { from: origin, to: destination };
});

const dates = computed(() =>
    formatDateRange(
        props.deal.departs_at ?? props.deal.published_at,
        props.deal.returns_at,
    ),
);

/**
 * A round trip is counted in nights away, a package holiday in days sold.
 */
const duration = computed(() => {
    if (props.deal.days === null) {
        return null;
    }

    return props.deal.type === 'round_trip'
        ? nightsLabel(props.deal.days)
        : daysLabel(props.deal.days);
});

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
    <article
        class="surface group relative flex cursor-pointer flex-col overflow-hidden transition-all duration-200 focus-within:ring-2 focus-within:ring-ring hover:-translate-y-0.5 hover:shadow-lift"
        :class="deal.steal ? 'border-good/40' : ''"
        role="button"
        tabindex="0"
        @click="emit('open')"
        @keydown.enter="emit('open')"
        @keydown.space.prevent="emit('open')"
    >
        <span
            v-if="deal.steal"
            aria-hidden="true"
            class="absolute inset-x-0 top-0 h-0.5 bg-good"
        />

        <div class="flex items-start justify-between gap-3 p-5 pb-4">
            <div class="flex min-w-0 flex-wrap items-center gap-1.5">
                <span
                    class="rounded-md bg-secondary px-2 py-0.5 text-xs font-medium text-secondary-foreground"
                >
                    {{ sourceLabel(deal.source) }}
                </span>
                <span
                    class="inline-flex items-center gap-1 rounded-md border border-border px-2 py-0.5 text-xs text-muted-foreground"
                >
                    <component
                        :is="
                            deal.type === 'round_trip' ? ArrowRightLeft : Plane
                        "
                        v-if="deal.type !== 'trip'"
                        class="size-3"
                    />
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
                    class="inline-flex items-center gap-1 rounded-md bg-good px-2 py-0.5 text-xs font-semibold text-white"
                >
                    <TrendingDown class="size-3" />
                    okazja −{{ deal.discount }}%
                </span>
            </div>

            <ScoreRing
                v-if="deal.score !== null"
                :score="deal.score"
                :threshold="scoreThreshold"
            />
        </div>

        <div class="px-5 pb-5">
            <div v-if="route" class="space-y-1.5">
                <div class="flex items-center gap-3">
                    <div class="min-w-0">
                        <p
                            class="text-2xl leading-none font-semibold tracking-tight"
                        >
                            {{ route.from.code }}
                        </p>
                        <p
                            v-if="route.from.city !== route.from.code"
                            class="mt-1 truncate text-xs text-muted-foreground"
                        >
                            {{ route.from.city }}
                        </p>
                    </div>

                    <div
                        class="flex flex-1 items-center gap-1.5 text-muted-foreground/60"
                        aria-hidden="true"
                    >
                        <span class="h-px flex-1 bg-current opacity-40" />
                        <component
                            :is="
                                deal.type === 'round_trip'
                                    ? ArrowRightLeft
                                    : Plane
                            "
                            class="size-3.5"
                        />
                        <span class="h-px flex-1 bg-current opacity-40" />
                    </div>

                    <div class="min-w-0 text-right">
                        <p
                            class="text-2xl leading-none font-semibold tracking-tight"
                        >
                            {{ route.to.code }}
                        </p>
                        <p
                            v-if="route.to.city !== route.to.code"
                            class="mt-1 truncate text-xs text-muted-foreground"
                        >
                            {{ route.to.city }}
                        </p>
                    </div>
                </div>
                <p
                    v-if="route.to.country"
                    class="text-xs text-muted-foreground"
                >
                    {{ route.to.country }}
                </p>
            </div>

            <div v-else class="space-y-1.5">
                <h3
                    class="line-clamp-2 leading-snug font-semibold text-balance"
                >
                    {{ deal.title }}
                </h3>
                <p
                    v-if="deal.destination?.country"
                    class="text-xs text-muted-foreground"
                >
                    {{ deal.destination.country }}
                </p>
            </div>

            <div
                class="mt-4 flex flex-wrap items-center gap-x-3 gap-y-1.5 text-xs text-muted-foreground"
            >
                <span v-if="dates" class="inline-flex items-center gap-1.5">
                    <CalendarDays class="size-3.5" />
                    <span class="numeric">{{ dates }}</span>
                </span>
                <span v-if="duration" class="inline-flex items-center gap-1.5">
                    <MoonStar class="size-3.5" />
                    {{ duration }}
                </span>
                <span
                    v-if="deal.board"
                    class="inline-flex items-center gap-1.5"
                >
                    <UtensilsCrossed class="size-3.5" />
                    {{ boardLabels[deal.board] }}
                </span>
                <span
                    v-if="deal.hotel_stars"
                    class="inline-flex items-center gap-1"
                >
                    <Star class="size-3.5 fill-warn text-warn" />
                    <span class="numeric">{{ deal.hotel_stars }}</span>
                </span>
            </div>
        </div>

        <div
            class="mt-auto flex items-end justify-between gap-3 border-t border-border bg-muted/30 px-5 py-4"
        >
            <div class="min-w-0">
                <p class="text-2xl leading-none font-semibold tracking-tight">
                    {{ formatPrice(deal.price, deal.currency) }}
                </p>
                <p class="mt-1.5 truncate text-xs text-muted-foreground">
                    {{ priceCaption }}
                </p>
            </div>

            <!--
                Deliberately not a stretched link: the card itself opens the
                details, and only this button leaves for the source.
            -->
            <a
                :href="deal.url"
                target="_blank"
                rel="noopener noreferrer"
                class="inline-flex shrink-0 items-center gap-1 rounded-lg bg-primary px-3.5 py-2 text-sm font-medium text-primary-foreground transition-colors hover:bg-primary/90 focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-card focus-visible:outline-none"
                @click.stop
            >
                Zobacz
                <ArrowUpRight class="size-4" />
                <span class="sr-only">
                    ofertę {{ deal.title }} w nowej karcie
                </span>
            </a>
        </div>
    </article>
</template>
