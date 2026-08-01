<script setup lang="ts">
import { computed } from 'vue';
import type { TravelWindow } from '@/types';

const props = defineProps<{
    /** Dates the offer runs on. */
    windows: TravelWindow[];
    /** Marked apart from the rest - the leg out and the way back. */
    departsAt?: string | null;
    returnsAt?: string | null;
}>();

type Day = {
    key: string;
    number: number;
    marked: boolean;
    edge: 'from' | 'to' | null;
    label: string | null;
};

type Month = {
    key: string;
    label: string;
    /** Blank cells before the first, so the columns line up with weekdays. */
    offset: number;
    days: Day[];
};

const weekdays = ['pon', 'wt', 'śr', 'czw', 'pt', 'sob', 'ndz'];

const toKey = (date: Date) =>
    `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;

const parse = (value: string) => new Date(`${value.slice(0, 10)}T00:00:00`);

/**
 * Every day the offer covers, keyed by date, with the wording the article used
 * so hovering a day says what the blog said.
 */
const marked = computed(() => {
    const days = new Map<
        string,
        { label: string; edge: 'from' | 'to' | null }
    >();

    const mark = (
        value: string | null | undefined,
        label: string,
        edge: 'from' | 'to' | null,
    ) => {
        if (!value) {
            return;
        }

        days.set(toKey(parse(value)), { label, edge });
    };

    for (const window of props.windows) {
        const from = parse(window.from);
        const to = window.to === null ? from : parse(window.to);

        for (
            let day = new Date(from);
            day <= to;
            day.setDate(day.getDate() + 1)
        ) {
            days.set(toKey(day), { label: window.label, edge: null });
        }
    }

    mark(props.departsAt, 'wylot', 'from');
    mark(props.returnsAt, 'powrót', 'to');

    return days;
});

/**
 * Only the months that actually hold something - an empty grid is noise.
 */
const months = computed<Month[]>(() => {
    const keys = [...marked.value.keys()].sort();

    if (keys.length === 0) {
        return [];
    }

    const first = parse(keys[0]);
    const last = parse(keys[keys.length - 1]);
    const result: Month[] = [];

    for (
        let cursor = new Date(first.getFullYear(), first.getMonth(), 1);
        cursor <= new Date(last.getFullYear(), last.getMonth(), 1);
        cursor.setMonth(cursor.getMonth() + 1)
    ) {
        const year = cursor.getFullYear();
        const month = cursor.getMonth();
        const daysInMonth = new Date(year, month + 1, 0).getDate();
        const days: Day[] = [];

        for (let number = 1; number <= daysInMonth; number++) {
            const key = toKey(new Date(year, month, number));
            const hit = marked.value.get(key);

            days.push({
                key,
                number,
                marked: hit !== undefined,
                edge: hit?.edge ?? null,
                label: hit?.label ?? null,
            });
        }

        result.push({
            key: `${year}-${month}`,
            label: new Date(year, month, 1).toLocaleDateString('pl-PL', {
                month: 'long',
                year: 'numeric',
            }),
            // Monday-first, so Sunday closes the week as it does on a wall.
            offset: (new Date(year, month, 1).getDay() + 6) % 7,
            days,
        });
    }

    return result;
});
</script>

<template>
    <div v-if="months.length > 0" class="grid gap-4 sm:grid-cols-2">
        <div
            v-for="month in months"
            :key="month.key"
            class="rounded-xl border border-border p-3"
        >
            <p class="mb-2 text-sm font-medium capitalize">{{ month.label }}</p>

            <div
                class="grid grid-cols-7 gap-1 text-center text-[10px] text-muted-foreground"
            >
                <span v-for="weekday in weekdays" :key="weekday">
                    {{ weekday }}
                </span>
            </div>

            <div class="mt-1 grid grid-cols-7 gap-1 text-center text-xs">
                <span
                    v-for="blank in month.offset"
                    :key="`blank-${blank}`"
                    aria-hidden="true"
                />

                <span
                    v-for="day in month.days"
                    :key="day.key"
                    class="rounded-md py-1 tabular-nums"
                    :class="[
                        day.marked
                            ? 'font-semibold text-primary-foreground'
                            : 'text-muted-foreground',
                        day.marked && day.edge === null ? 'bg-primary' : '',
                        day.edge !== null ? 'bg-good' : '',
                    ]"
                    :title="day.label ?? undefined"
                >
                    {{ day.number }}
                </span>
            </div>
        </div>
    </div>
</template>
