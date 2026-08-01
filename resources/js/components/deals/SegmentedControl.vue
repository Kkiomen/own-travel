<script setup lang="ts">
defineProps<{
    modelValue: string;
    options: { value: string; label: string }[];
    groupLabel: string;
}>();

const emit = defineEmits<{ select: [value: string] }>();
</script>

<template>
    <div
        class="inline-flex items-center gap-0.5 rounded-full border border-border bg-muted/60 p-1"
        role="group"
        :aria-label="groupLabel"
    >
        <button
            v-for="option in options"
            :key="option.value"
            type="button"
            :aria-pressed="modelValue === option.value"
            class="rounded-full px-3.5 py-1.5 text-sm whitespace-nowrap transition-colors focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
            :class="
                modelValue === option.value
                    ? 'bg-card font-medium text-foreground shadow-card'
                    : 'text-muted-foreground hover:text-foreground'
            "
            @click="emit('select', option.value)"
        >
            {{ option.label }}
        </button>
    </div>
</template>
