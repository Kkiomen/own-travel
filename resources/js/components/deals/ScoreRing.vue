<script setup lang="ts">
import { computed } from 'vue';

/**
 * The rating an offer earned, on the same 0-100 scale flights and trips share.
 * The number is always spelled out inside the ring, so the verdict never rests
 * on the colour of the arc.
 */
const props = defineProps<{
    score: number;
    /** At or above this, the offer is worth being told about. */
    threshold: number;
}>();

const RADIUS = 16;
const CIRCUMFERENCE = 2 * Math.PI * RADIUS;

const dash = computed(
    () => (Math.min(100, Math.max(0, props.score)) / 100) * CIRCUMFERENCE,
);

const tone = computed(() => {
    if (props.score >= props.threshold) {
        return { arc: 'stroke-good', text: 'text-good-foreground' };
    }

    return props.score >= 40
        ? { arc: 'stroke-warn', text: 'text-warn-foreground' }
        : { arc: 'stroke-muted-foreground/50', text: 'text-muted-foreground' };
});
</script>

<template>
    <div
        class="relative inline-flex size-10 shrink-0 items-center justify-center"
        :title="`Ocena ${score} na 100`"
    >
        <svg viewBox="0 0 40 40" class="size-10 -rotate-90" aria-hidden="true">
            <circle
                cx="20"
                cy="20"
                :r="RADIUS"
                fill="none"
                stroke-width="3"
                class="stroke-track"
            />
            <circle
                cx="20"
                cy="20"
                :r="RADIUS"
                fill="none"
                stroke-width="3"
                stroke-linecap="round"
                :stroke-dasharray="`${dash} ${CIRCUMFERENCE}`"
                :class="tone.arc"
            />
        </svg>
        <span
            class="numeric absolute text-[0.7rem] font-semibold"
            :class="tone.text"
        >
            {{ score }}
        </span>
        <span class="sr-only">punktów na 100</span>
    </div>
</template>
