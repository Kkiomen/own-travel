<script setup lang="ts">
import type { LucideIcon } from '@lucide/vue';

/**
 * A toggle that narrows the list. `tone` is the state it turns on, never
 * decoration — and the label says the same thing the colour does.
 */
withDefaults(
    defineProps<{
        active: boolean;
        label: string;
        icon?: LucideIcon;
        tone?: 'primary' | 'good';
    }>(),
    { icon: undefined, tone: 'primary' },
);

const emit = defineEmits<{ toggle: [] }>();
</script>

<template>
    <button
        type="button"
        :aria-pressed="active"
        class="inline-flex items-center gap-1.5 rounded-full border px-3.5 py-2 text-sm whitespace-nowrap transition-colors focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
        :class="
            active
                ? tone === 'good'
                    ? 'border-good bg-good text-white'
                    : 'border-primary bg-primary text-primary-foreground'
                : 'border-border bg-card text-muted-foreground hover:text-foreground'
        "
        @click="emit('toggle')"
    >
        <component :is="icon" v-if="icon" class="size-3.5" />
        {{ label }}
    </button>
</template>
