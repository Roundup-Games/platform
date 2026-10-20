import { defineConfig, devices } from '@playwright/test';

/**
 * Playwright configuration for runtime-executable browser UAT.
 *
 * The stack lifecycle is owned by the caller (GSD run-uat, or a developer
 * running `composer run dev`), so this config deliberately has NO webServer
 * block: it never spawns servers itself. Point tests at a running stack via
 * PLAYWRIGHT_BASE_URL, defaulting to the `php artisan serve` port (:8000)
 * that `composer run dev` serves under the "server" name.
 */
export default defineConfig({
    testDir: './e2e',
    fullyParallel: true,
    retries: 0,
    reporter: 'list',
    use: {
        baseURL: process.env.PLAYWRIGHT_BASE_URL ?? 'http://localhost:8000',
    },
    projects: [
        {
            name: 'chromium',
            use: { ...devices['Desktop Chrome'] },
        },
    ],
});
