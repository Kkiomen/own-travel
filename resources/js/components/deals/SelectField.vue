<script setup lang="ts">
import { ChevronDown } from '@lucide/vue';
import { computed, useId } from 'vue';

type Option = { value: string; label: string };

const props = defineProps<{
    label: string;
    modelValue: string;
    options: Option[];
    /** Shown as the "no filter" choice at the top. */
    placeholder: string;
}>();

const emit = defineEmits<{ 'update:modelValue': [value: string] }>();

const id = useId();

/**
 * The options answer to the other filters, so what is currently picked can fall
 * out of them - turn on "mega okazje" and the chosen destination may have none.
 * Keeping it in the list means the control still shows what it is filtering by,
 * and can still be cleared.
 */
const choices = computed<Option[]>(() => {
    if (
        props.modelValue === '' ||
        props.options.some((option) => option.value === props.modelValue)
    ) {
        return props.options;
    }

    return [
        { value: props.modelValue, label: props.modelValue },
        ...props.options,
    ];
});

/** Nothing to choose from and nothing chosen - the control is just clutter. */
const isUseful = computed(() => choices.value.length > 0);
</script>

<template>
    <div v-if="isUseful" class="flex items-center gap-2">
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
                    v-for="option in choices"
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
