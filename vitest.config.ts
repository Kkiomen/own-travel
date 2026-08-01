import vue from '@vitejs/plugin-vue';
import { fileURLToPath } from 'node:url';
import { defineConfig } from 'vitest/config';

/**
 * Deliberately not the app's vite.config.ts. That one runs Wayfinder, which
 * shells out to `php artisan`, and the Laravel plugin, which wants a manifest -
 * a component test needs neither and should not need a database or PHP to run.
 */
export default defineConfig({
    plugins: [vue()],
    resolve: {
        alias: {
            '@': fileURLToPath(new URL('./resources/js', import.meta.url)),
        },
    },
    test: {
        environment: 'happy-dom',
        include: ['resources/js/**/*.spec.ts'],
    },
});
