import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js', 'resources/js/posthog.js', 'resources/css/filament/admin.css'],
            refresh: true,
        }),
        tailwindcss(),
    ],
    build: {
        // Production bundles must not ship console noise (subscription ids,
        // service-worker chatter). Dev-mode logs are already gated behind
        // import.meta.env.DEV; this drops the remaining unconditional calls.
        esbuild: {
            drop: ['console'],
        },
    },
});
