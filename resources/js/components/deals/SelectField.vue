<script setup lang="ts">
import { ChevronDown } from '@lucide/vue';
import { useId } from 'vue';

defineProps<{
    label: string;
    modelValue: string;
    options: { value: string; label: string }[];
    /** Shown as the "no filter" choice at the top. */
    placeholder: string;
}>();

const emit = defineEmits<{ 'update:modelValue': [value: string] }>();

const id = useId();
</script>

<template>
    <div class="flex items-center gap-2">
        <label :for="id" class="text-sm text-muted-foreground">{{
            label
        }}</label>
        <div class="relative">
            <select
                :id="id"
                :value="modelValue"
                class="appearance-none rounded-full border border-border bg-card py-2 pr-8 pl-3.5 text-sm transition-colors hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                :class="
                    modelValue === '' ? 'text-muted-foreground' : 'font-medium'
                "
                @change="
                    emit(
                        'update:modelValue',
                        ($event.target as HTMLSelectElement).value,
                    )
                "
            >
                <option value="">{{ placeholder }}</option>
                <option
                    v-for="option in options"
                    :key="option.value"
                    :value="option.value"
                >
                    {{ option.label }}
                </option>
            </select>
            <ChevronDown
                class="pointer-events-none absolute top-1/2 right-3 size-3.5 -translate-y-1/2 text-muted-foreground"
                aria-hidden="true"
            />
        </div>
    </div>
</template>
