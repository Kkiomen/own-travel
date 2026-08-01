<script setup lang="ts">
import { Radar, SearchX } from '@lucide/vue';

/**
 * Nothing on screen has two very different causes: the radar has not found
 * anything yet, or the filters are hiding what it did. Saying which one it is
 * saves the owner from wondering whether the scan is broken.
 */
defineProps<{ filtered: boolean }>();

const emit = defineEmits<{ clear: [] }>();
</script>

<template>
    <div
        class="flex flex-col items-center rounded-2xl border border-dashed border-border px-6 py-16 text-center"
    >
        <span
            class="inline-flex size-12 items-center justify-center rounded-2xl bg-muted text-muted-foreground"
        >
            <component :is="filtered ? SearchX : Radar" class="size-6" />
        </span>

        <p class="mt-4 font-medium">
            {{
                filtered ? 'Nic nie pasuje do filtrów' : 'Radar jeszcze milczy'
            }}
        </p>

        <p class="mt-1.5 max-w-sm text-sm text-muted-foreground">
            <template v-if="filtered">
                Okazje są, ale żadna nie spełnia tych warunków. Poluzuj filtry,
                żeby zobaczyć resztę.
            </template>
            <template v-else>
                Skanowanie rusza co godzinę. Możesz je też wywołać ręcznie:
                <code
                    class="mt-2 inline-block rounded-md bg-muted px-2 py-1 font-mono text-xs"
                    >php artisan deals:scan</code
                >
            </template>
        </p>

        <button
            v-if="filtered"
            type="button"
            class="mt-5 rounded-full bg-primary px-4 py-2 text-sm font-medium text-primary-foreground transition-colors hover:bg-primary/90 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
            @click="emit('clear')"
        >
            Wyczyść filtry
        </button>
    </div>
</template>
