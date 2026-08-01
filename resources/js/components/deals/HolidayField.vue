<script setup lang="ts">
import { CalendarRange, X } from '@lucide/vue';
import { computed, ref, watch } from 'vue';

const props = defineProps<{
    /** ISO dates, empty when that end has not been picked yet. */
    from: string;
    to: string;
}>();

const emit = defineEmits<{ change: [dates: { from: string; to: string }] }>();

/**
 * The range being typed, which is not the same thing as the range being
 * filtered by. Only a whole range narrows anything, so the server is told
 * nothing until both ends are known - and until it is told, the props stay
 * empty. Reading the first day back off them would drop it the moment the
 * second was picked, which is why this has to be held here.
 */
const draft = ref({ from: props.from, to: props.to });

// Whatever the server settled on wins, including a range it refused.
watch(
    () => [props.from, props.to],
    ([from, to]) => {
        draft.value = { from, to };
    },
);

const isSet = computed(() => draft.value.from !== '' && draft.value.to !== '');

/**
 * One day picked is enough to want it gone again: a half-typed range is not
 * filtering anything, and without this the only way back out is the native
 * picker.
 */
const hasAnyDay = computed(
    () => draft.value.from !== '' || draft.value.to !== '',
);

const submit = () => {
    // Both ends, or neither: a half-filled range is one still being typed, and
    // asking for it would only clear the filter.
    if (isSet.value || (draft.value.from === '' && draft.value.to === '')) {
        emit('change', { ...draft.value });
    }
};

const setFrom = (value: string) => {
    // Picking a start past the existing end can only mean the range is being
    // moved, never that it should run backwards.
    const to =
        draft.value.to !== '' && draft.value.to < value
            ? value
            : draft.value.to;

    draft.value = { from: value, to };
    submit();
};

const setTo = (value: string) => {
    draft.value = { ...draft.value, to: value };
    submit();
};

const clear = () => {
    draft.value = { from: '', to: '' };

    // Only worth a round trip if a holiday is actually being filtered by;
    // dropping a half-typed range changes nothing the server knows about.
    if (props.from !== '' || props.to !== '') {
        emit('change', { from: '', to: '' });
    }
};
</script>

<template>
    <div class="flex items-center gap-2">
        <CalendarRange
            class="size-4 shrink-0 text-muted-foreground"
            aria-hidden="true"
        />
        <span class="text-sm text-muted-foreground">Urlop</span>

        <div class="flex items-center gap-1.5">
            <input
                type="date"
                :value="draft.from"
                aria-label="Pierwszy dzień urlopu"
                class="rounded-full border border-border bg-card px-3 py-2 text-sm transition-colors focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                :class="
                    draft.from === '' ? 'text-muted-foreground' : 'font-medium'
                "
                @change="setFrom(($event.target as HTMLInputElement).value)"
            />
            <span class="text-sm text-muted-foreground">–</span>
            <input
                type="date"
                :value="draft.to"
                :min="draft.from === '' ? undefined : draft.from"
                aria-label="Ostatni dzień urlopu"
                class="rounded-full border border-border bg-card px-3 py-2 text-sm transition-colors focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                :class="
                    draft.to === '' ? 'text-muted-foreground' : 'font-medium'
                "
                @change="setTo(($event.target as HTMLInputElement).value)"
            />

            <button
                v-if="hasAnyDay"
                type="button"
                aria-label="Wyczyść urlop"
                class="rounded-full p-1.5 text-muted-foreground transition-colors hover:bg-muted hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                @click="clear()"
            >
                <X class="size-3.5" aria-hidden="true" />
            </button>
        </div>
    </div>
</template>
