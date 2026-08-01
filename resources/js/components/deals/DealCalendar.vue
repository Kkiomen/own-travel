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

/**
 * Which leg of the stay a day is. Marking both ends the same way left the days
 * between them blank, so a trip read as two unrelated dates rather than as time
 * away - the thing a calendar is there to show.
 */
type Leg = 'out' | 'stay' | 'back';

type Marked = {
    leg: Leg;
    label: string;
    /** Whether the coloured band starts or stops here, so it can be capped. */
    opens: boolean;
    closes: boolean;
};

type Day = {
    key: string;
    number: number;
    mark: Marked | null;
};

type Month = {
    key: string;
    label: string;
    /** Blank cells before the first, so the columns line up with weekdays. */
    offset: number;
    days: Day[];
};

const weekdays = ['pon', 'wt', 'śr', 'czw', 'pt', 'sob', 'ndz'];

const legNames: Record<Leg, string> = {
    out: 'wylot',
    stay: 'pobyt',
    back: 'powrót',
};

const toKey = (date: Date) =>
    `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;

/**
 * The day a date falls on here, at midnight, so days can be stepped through
 * and compared.
 *
 * A blog names a bare day and means it. An airline names an instant, and the
 * day that instant belongs to is the local one: a flight at 22:30 UTC leaves
 * on the 29th in Warsaw, and cutting the date out of the string put the whole
 * stay on the calendar a day early - while every other date on the page, read
 * with the offset, said the 29th.
 */
const parse = (value: string): Date => {
    const at = /^\d{4}-\d{2}-\d{2}$/.test(value.trim())
        ? new Date(`${value}T00:00:00`)
        : new Date(value);

    return new Date(at.getFullYear(), at.getMonth(), at.getDate());
};

/** Stepping by calendar day rather than by 24 hours, so a clock change cannot skip one. */
const addDays = (date: Date, days: number): Date =>
    new Date(date.getFullYear(), date.getMonth(), date.getDate() + days);

/**
 * A blog names the terms it sells; an airline names the two flights. Both come
 * down to the same thing - a first day, a last day and the time in between.
 */
const stays = computed(() => {
    const stays: { from: Date; to: Date; label: string }[] = [];

    for (const window of props.windows) {
        const from = parse(window.from);

        stays.push({
            from,
            to: window.to === null ? from : parse(window.to),
            label: window.label,
        });
    }

    if (props.departsAt) {
        const from = parse(props.departsAt);

        stays.push({
            from,
            to: props.returnsAt ? parse(props.returnsAt) : from,
            label: props.returnsAt ? 'termin wyjazdu' : 'wylot',
        });
    }

    return stays;
});

/**
 * Every day the offer covers, keyed by date, with the wording the article used
 * so hovering a day says what the blog said.
 */
const marked = computed(() => {
    const days = new Map<string, Marked>();

    for (const stay of stays.value) {
        const firstKey = toKey(stay.from);
        const lastKey = toKey(stay.to);

        for (let day = stay.from; day <= stay.to; day = addDays(day, 1)) {
            const key = toKey(day);
            const opens = key === firstKey;
            const closes = key === lastKey;
            // A single-day term is a departure, not a return.
            const leg: Leg = opens ? 'out' : closes ? 'back' : 'stay';

            // Where terms overlap, the day an offer flies on outranks a day it
            // merely spans.
            if (leg === 'stay' && days.get(key)?.leg !== undefined) {
                continue;
            }

            days.set(key, { leg, label: stay.label, opens, closes });
        }
    }

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

            days.push({
                key,
                number,
                mark: marked.value.get(key) ?? null,
            });
        }

        // Terms can be months apart, and the months between them hold nothing
        // to look at.
        if (!days.some((day) => day.mark !== null)) {
            continue;
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

/** The key says what each colour means - it never has to be guessed. */
const legend = computed<Leg[]>(() =>
    (['out', 'stay', 'back'] as Leg[]).filter((leg) =>
        [...marked.value.values()].some((mark) => mark.leg === leg),
    ),
);

const swatches: Record<Leg, string> = {
    out: 'bg-leg-out',
    stay: 'bg-stay',
    back: 'bg-leg-back',
};

const dayTitle = (mark: Marked) => `${legNames[mark.leg]} · ${mark.label}`;
</script>

<template>
    <div v-if="months.length > 0" class="space-y-3">
        <div class="grid gap-4 sm:grid-cols-2">
            <div
                v-for="month in months"
                :key="month.key"
                class="rounded-xl border border-border p-3"
            >
                <p class="mb-2 text-sm font-medium capitalize">
                    {{ month.label }}
                </p>

                <div
                    class="grid grid-cols-7 text-center text-[10px] text-muted-foreground"
                >
                    <span v-for="weekday in weekdays" :key="weekday">
                        {{ weekday }}
                    </span>
                </div>

                <div class="mt-1 grid grid-cols-7 gap-y-1 text-center text-xs">
                    <span
                        v-for="blank in month.offset"
                        :key="`blank-${blank}`"
                        aria-hidden="true"
                    />

                    <!--
                      The band sits behind the numbers and runs edge to edge, so
                      the days of one stay read as a single stretch instead of a
                      row of separate boxes.
                    -->
                    <div
                        v-for="day in month.days"
                        :key="day.key"
                        class="relative flex h-8 items-center justify-center"
                        :title="day.mark ? dayTitle(day.mark) : undefined"
                    >
                        <span
                            v-if="day.mark"
                            aria-hidden="true"
                            class="absolute inset-y-0.5 right-0 left-0 bg-stay"
                            :class="[
                                day.mark.opens ? 'left-1 rounded-l-full' : '',
                                day.mark.closes ? 'right-1 rounded-r-full' : '',
                            ]"
                        />

                        <span
                            class="numeric relative flex size-7 items-center justify-center rounded-full"
                            :class="{
                                'bg-leg-out font-semibold text-leg-out-foreground':
                                    day.mark?.leg === 'out',
                                'bg-leg-back font-semibold text-leg-back-foreground':
                                    day.mark?.leg === 'back',
                                'font-medium text-foreground':
                                    day.mark?.leg === 'stay',
                                'text-muted-foreground/70': day.mark === null,
                            }"
                        >
                            {{ day.number }}
                            <span v-if="day.mark" class="sr-only">
                                {{ legNames[day.mark.leg] }}
                            </span>
                        </span>
                    </div>
                </div>
            </div>
        </div>

        <p
            class="flex flex-wrap items-center gap-x-4 gap-y-1.5 text-xs text-muted-foreground"
        >
            <span
                v-for="leg in legend"
                :key="leg"
                class="inline-flex items-center gap-1.5"
            >
                <span
                    aria-hidden="true"
                    class="size-2.5 rounded-full"
                    :class="swatches[leg]"
                />
                {{ legNames[leg] }}
            </span>
        </p>
    </div>
</template>
