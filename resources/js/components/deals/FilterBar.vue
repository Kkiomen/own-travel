<script setup lang="ts">
import { CalendarHeart, Flame } from '@lucide/vue';
import FilterChip from '@/components/deals/FilterChip.vue';
import HolidayField from '@/components/deals/HolidayField.vue';
import SegmentedControl from '@/components/deals/SegmentedControl.vue';
import SelectField from '@/components/deals/SelectField.vue';
import type {
    AirportOptions,
    DealFilters,
    DealSort,
    DealTypeFilter,
} from '@/types';

const props = defineProps<{
    filters: DealFilters;
    airports: AirportOptions;
}>();

const emit = defineEmits<{ change: [changes: Partial<DealFilters>] }>();

const kinds: { value: DealTypeFilter; label: string }[] = [
    { value: 'all', label: 'Wszystko' },
    { value: 'round_trip', label: 'Tam i z powrotem' },
    { value: 'flight', label: 'W jedną stronę' },
    { value: 'trip', label: 'Wycieczki' },
];

const sorts: { value: DealSort; label: string }[] = [
    { value: 'score', label: 'Najlepsze' },
    { value: 'price', label: 'Najtańsze' },
    { value: 'newest', label: 'Najnowsze' },
];

const airportOptions = (
    airports: AirportOptions['origins'],
): { value: string; label: string }[] =>
    airports.map((airport) => ({
        value: airport.code,
        label:
            airport.label === airport.code
                ? airport.code
                : `${airport.label} (${airport.code})`,
    }));

/**
 * Weekend pairings only exist for round trips, so asking for them implies the
 * kind - otherwise the filter would silently return nothing.
 */
/**
 * The control hands back a plain string, so the value is matched against the
 * options it was built from rather than cast into a type it may not be.
 */
const setKind = (value: string) => {
    const kind = kinds.find((option) => option.value === value);

    if (kind !== undefined) {
        emit('change', { type: kind.value });
    }
};

const setSort = (value: string) => {
    const sort = sorts.find((option) => option.value === value);

    if (sort !== undefined) {
        emit('change', { sort: sort.value });
    }
};

const toggleWeekends = () =>
    emit('change', {
        weekends: !props.filters.weekends,
        type: props.filters.weekends ? props.filters.type : 'round_trip',
    });
</script>

<template>
    <div
        class="sticky top-16 z-30 -mx-4 border-b border-border/70 bg-background/85 px-4 py-3 backdrop-blur-xl sm:-mx-6 sm:px-6 lg:-mx-8 lg:px-8"
    >
        <div class="flex flex-wrap items-center gap-x-3 gap-y-2">
            <SegmentedControl
                :model-value="filters.type"
                :options="kinds"
                group-label="Rodzaj okazji"
                @select="setKind"
            />

            <FilterChip
                :active="filters.weekends"
                :icon="CalendarHeart"
                label="Weekendy"
                @toggle="toggleWeekends()"
            />

            <FilterChip
                :active="filters.steals"
                :icon="Flame"
                label="Mega okazje"
                tone="good"
                @toggle="emit('change', { steals: !filters.steals })"
            />

            <HolidayField
                :from="filters.from"
                :to="filters.to"
                @change="emit('change', $event)"
            />

            <div class="flex flex-wrap items-center gap-3">
                <SelectField
                    label="Skąd"
                    placeholder="dowolne lotnisko"
                    :model-value="filters.origin"
                    :options="airportOptions(airports.origins)"
                    @update:model-value="emit('change', { origin: $event })"
                />
                <SelectField
                    label="Dokąd"
                    placeholder="dowolny kierunek"
                    :model-value="filters.destination"
                    :options="airportOptions(airports.destinations)"
                    @update:model-value="
                        emit('change', { destination: $event })
                    "
                />
            </div>

            <div class="ml-auto">
                <SegmentedControl
                    :model-value="filters.sort"
                    :options="sorts"
                    group-label="Sortowanie"
                    @select="setSort"
                />
            </div>
        </div>
    </div>
</template>
