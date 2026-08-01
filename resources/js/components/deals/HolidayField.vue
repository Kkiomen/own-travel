<script setup lang="ts">
import { CalendarRange, X } from '@lucide/vue';
import { computed, useId } from 'vue';

const props = defineProps<{
    /** ISO dates, empty when that end has not been picked yet. */
    from: string;
    to: string;
}>();

const emit = defineEmits<{ change: [dates: { from: string; to: string }] }>();

const fromId = useId();
const toId = useId();

const isSet = computed(() => props.from !== '' && props.to !== '');

/**
 * Only a full range narrows anything, so a half-filled control is not an error
 * - it is a range still being typed. Leave that ends before it starts is,
 * though, and the server would drop it silently; saying so here is kinder than
 * a page that quietly ignores what was asked.
 */
const isBackwards = computed(
    () => props.from !== '' && props.to !== '' && props.to < props.from,
);

const setFrom = (value: string) => {
    // Picking a start past the existing end would only ever mean the range is
    // being moved, so the end follows rather than turning the range backwards.
    const to = props.to !== '' && props.to < value ? value : props.to;

    emit('change', { from: value, to });
};

const clear = () => emit('change', { from: '', to: '' });
</script>

<template>
    <div class="flex items-center gap-2">
        <CalendarRange
            class="size-4 shrink-0 text-muted-foreground"
            aria-hidden="true"
        />
        <label :for="fromId" class="text-sm text-muted-foreground">Urlop</label>

        <div class="flex items-center gap-1.5">
            <input
                :id="fromId"
                type="date"
                :value="from"
                aria-label="Pierwszy dzień urlopu"
                class="rounded-full border border-border bg-card px-3 py-2 text-sm transition-colors focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                :class="from === '' ? 'text-muted-foreground' : 'font-medium'"
                @change="setFrom(($event.target as HTMLInputElement).value)"
            />
            <span class="text-sm text-muted-foreground">–</span>
            <input
                :id="toId"
                type="date"
                :value="to"
                :min="from === '' ? undefined : from"
                aria-label="Ostatni dzień urlopu"
                class="rounded-full border border-border bg-card px-3 py-2 text-sm transition-colors focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                :class="to === '' ? 'text-muted-foreground' : 'font-medium'"
                @change="
                    emit('change', {
                        from,
                        to: ($event.target as HTMLInputElement).value,
                    })
                "
            />

            <button
                v-if="isSet"
                type="button"
                aria-label="Wyczyść urlop"
                class="rounded-full p-1.5 text-muted-foreground transition-colors hover:bg-muted hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                @click="clear()"
            >
                <X class="size-3.5" aria-hidden="true" />
            </button>
        </div>

        <p v-if="isBackwards" class="text-sm text-destructive">
            Koniec urlopu wypada przed początkiem.
        </p>
    </div>
</template>
