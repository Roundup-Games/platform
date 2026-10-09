import { defineConfig } from 'vitest/config';

// Standalone test config — takes precedence over vite.config.js so the
// Laravel Vite plugin and the production build pipeline never load in tests.
export default defineConfig({
    test: {
        environment: 'happy-dom',
        include: ['tests/js/**/*.test.js'],
    },
});
