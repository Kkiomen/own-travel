<script setup lang="ts">
import AppHeader from '@/components/AppHeader.vue';
import { Toaster } from '@/components/ui/sonner';
</script>

<template>
    <div class="flex min-h-screen flex-col bg-background text-foreground">
        <AppHeader />

        <main class="flex-1">
            <slot />
        </main>

        <footer
            class="mx-auto w-full max-w-[100rem] px-4 py-8 text-xs text-muted-foreground sm:px-6 lg:px-8"
        >
            Prywatny radar okazji. Ceny pochodzą ze źródeł zewnętrznych i mogą
            się zmienić przed rezerwacją.
        </footer>

        <Toaster />
    </div>
</template>
