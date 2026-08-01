<script setup lang="ts">
import type { HTMLAttributes } from 'vue';
import { cn } from '@/lib/utils';

defineOptions({ inheritAttrs: false });

const props = defineProps<{ class?: HTMLAttributes['class'] }>();
</script>

<template>
    <span
        :class="
            cn(
                'inline-flex size-9 items-center justify-center rounded-xl bg-primary text-primary-foreground shadow-card',
                props.class,
            )
        "
        v-bind="$attrs"
    >
        <svg viewBox="0 0 24 24" fill="none" aria-hidden="true" class="size-5">
            <path
                d="M21.3 3.1 2.9 10.4a.55.55 0 0 0 .05 1.03l4.9 1.6 1.9 5.6a.55.55 0 0 0 1 .12l2.4-3.6 4.6 3.4a.55.55 0 0 0 .87-.31l3.3-14.4a.55.55 0 0 0-.72-.64Z"
                fill="currentColor"
            />
            <path
                d="m8.05 13.15 10.2-7.4-6.5 9.1"
                stroke="var(--primary)"
                stroke-width="1.2"
                stroke-linecap="round"
                stroke-linejoin="round"
                opacity="0.55"
            />
        </svg>
    </span>
</template>
