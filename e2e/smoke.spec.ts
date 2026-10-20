import { expect, test } from '@playwright/test';

/**
 * Data-independent smoke test: the home page is public and needs no fixtures.
 *
 * Precondition: the stack is already running locally — either via
 * `composer run dev` (server on http://localhost:8000) or with an explicit
 * PLAYWRIGHT_BASE_URL override pointing at a reachable environment.
 * The Playwright config never spawns servers itself; run-uat owns the
 * stack lifecycle.
 */
test('public home page loads', async ({ page }) => {
    const response = await page.goto('/');

    if (!response) {
        throw new Error('page.goto("/") returned no response — expected a document response');
    }

    expect(response.status()).toBeLessThan(400);
    await expect(page).toHaveTitle(/\S/);
});
