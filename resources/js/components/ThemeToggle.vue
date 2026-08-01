<script setup lang="ts">
import { Monitor, Moon, Sun } from '@lucide/vue';
import { useAppearance } from '@/composables/useAppearance';
import type { Appearance } from '@/types';

const { appearance, updateAppearance } = useAppearance();

const options: { value: Appearance; label: string; icon: typeof Sun }[] = [
    { value: 'light', label: 'Jasny', icon: Sun },
    { value: 'dark', label: 'Ciemny', icon: Moon },
    { value: 'system', label: 'Systemowy', icon: Monitor },
];
</script>

<template>
    <div
        class="inline-flex items-center gap-0.5 rounded-full border border-border bg-muted/60 p-0.5"
        role="group"
        aria-label="Motyw"
    >
        <button
            v-for="option in options"
            :key="option.value"
            type="button"
            :title="option.label"
            :aria-label="option.label"
            :aria-pressed="appearance === option.value"
            class="inline-flex size-7 items-center justify-center rounded-full transition-colors focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
            :class="
                appearance === option.value
                    ? 'bg-card text-foreground shadow-card'
                    : 'text-muted-foreground hover:text-foreground'
            "
            @click="updateAppearance(option.value)"
        >
            <component :is="option.icon" class="size-3.5" />
        </button>
    </div>
</template>
