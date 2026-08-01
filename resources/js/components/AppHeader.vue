<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { Radar } from '@lucide/vue';
import BrandMark from '@/components/BrandMark.vue';
import ThemeToggle from '@/components/ThemeToggle.vue';
import { dashboard } from '@/routes';

const name = usePage().props.name;
</script>

<template>
    <header
        class="sticky top-0 z-40 border-b border-border/70 bg-background/80 backdrop-blur-xl supports-[backdrop-filter]:bg-background/65"
    >
        <div
            class="mx-auto flex h-16 w-full max-w-[100rem] items-center gap-4 px-4 sm:px-6 lg:px-8"
        >
            <Link
                :href="dashboard()"
                class="flex items-center gap-3 rounded-xl focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
            >
                <BrandMark />
                <span class="flex flex-col leading-none">
                    <span class="text-sm font-semibold tracking-tight">{{
                        name
                    }}</span>
                    <span class="mt-1 text-xs text-muted-foreground"
                        >radar tanich lotów</span
                    >
                </span>
            </Link>

            <div class="ml-auto flex items-center gap-2 sm:gap-3">
                <span
                    class="hidden items-center gap-1.5 rounded-full border border-border bg-muted/50 px-3 py-1.5 text-xs text-muted-foreground sm:inline-flex"
                >
                    <Radar class="size-3.5 text-good" />
                    skanowanie co godzinę
                </span>
                <ThemeToggle />
            </div>
        </div>
    </header>
</template>
