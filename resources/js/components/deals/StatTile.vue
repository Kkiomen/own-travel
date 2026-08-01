<script setup lang="ts">
import type { LucideIcon } from '@lucide/vue';
import { computed } from 'vue';
import Meter from '@/components/deals/Meter.vue';
import { formatPrice } from '@/lib/dealFormat';

const props = defineProps<{
    label: string;
    icon: LucideIcon;
    count: number;
    /** The best price on offer, or null when nothing is being tracked. */
    cheapest: number | null;
    /** The price gate: nothing above it is kept at all. */
    threshold: number;
    currency: string;
}>();

/**
 * A find is a price well under the gate; something just below it is merely
 * within budget. Two thirds is where "cheap" stops feeling cheap.
 */
const tone = computed<'good' | 'warn' | 'neutral'>(() => {
    if (props.cheapest === null || props.threshold <= 0) {
        return 'neutral';
    }

    const share = props.cheapest / props.threshold;

    return share <= 0.45 ? 'good' : share <= 0.75 ? 'neutral' : 'warn';
});
</script>

<template>
    <div class="surface flex flex-col gap-4 p-5">
        <div class="flex items-center justify-between gap-3">
            <div class="flex items-center gap-2.5">
                <span
                    class="inline-flex size-8 items-center justify-center rounded-lg bg-primary/10 text-primary"
                >
                    <component :is="icon" class="size-4" />
                </span>
                <span class="text-sm font-medium text-muted-foreground">{{
                    label
                }}</span>
            </div>
            <span
                class="numeric rounded-full bg-muted px-2.5 py-1 text-xs font-semibold text-muted-foreground"
            >
                {{ count }}
            </span>
        </div>

        <p class="text-3xl leading-none font-semibold tracking-tight">
            <template v-if="cheapest === null">
                <span class="text-muted-foreground">brak</span>
            </template>
            <template v-else>
                <span class="text-base font-normal text-muted-foreground"
                    >od </span
                >{{ formatPrice(cheapest, currency) }}
            </template>
        </p>

        <div class="space-y-2">
            <Meter
                :value="cheapest ?? threshold"
                :max="threshold"
                :tone="tone"
                :label="`${label}: najtańsza oferta wobec progu`"
            />
            <p class="numeric text-xs text-muted-foreground">
                próg {{ formatPrice(threshold, currency) }}
            </p>
        </div>
    </div>
</template>
