<script setup lang="ts">
import { computed } from 'vue';

/**
 * How much of the budget an offer eats. The fill carries the verdict and the
 * track is a lighter step of the same hue, so the state reads across the whole
 * bar rather than only where it happens to end.
 */
const props = withDefaults(
    defineProps<{
        value: number;
        max: number;
        tone?: 'good' | 'warn' | 'neutral';
        label?: string;
    }>(),
    { tone: 'neutral', label: undefined },
);

const ratio = computed(() => {
    if (props.max <= 0) {
        return 0;
    }

    return Math.min(1, Math.max(0.02, props.value / props.max));
});

const tones = {
    good: 'bg-good',
    warn: 'bg-warn',
    neutral: 'bg-primary',
} as const;

const tracks = {
    good: 'bg-good-soft',
    warn: 'bg-warn-soft',
    neutral: 'bg-track',
} as const;
</script>

<template>
    <div
        class="h-1.5 w-full overflow-hidden rounded-full"
        :class="tracks[tone]"
        role="meter"
        :aria-valuenow="Math.round(ratio * 100)"
        aria-valuemin="0"
        aria-valuemax="100"
        :aria-label="label"
    >
        <div
            class="h-full rounded-full transition-[width] duration-500 ease-out"
            :class="tones[tone]"
            :style="{ width: `${ratio * 100}%` }"
        />
    </div>
</template>
