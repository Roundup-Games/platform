import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

/*
 * build-guard.js — deploy-transition guard (app.js's FIRST import). Contract:
 * exactly one consistent build may boot per window.
 *
 *   fresh load            → marker set, no reload, no throw
 *   pre-guard straggler   → window.Alpine present but no marker → reload + throw
 *   older-build tab       → marker differs → reload + throw
 *   same-build re-exec    → marker matches → proceeds
 */

const reload = vi.fn();

beforeEach(() => {
    vi.resetModules();
    delete window.__roundupBuild;
    delete window.Alpine;
    reload.mockClear();
    vi.stubGlobal('location', { ...window.location, reload });
});

afterEach(() => {
    vi.unstubAllGlobals();
});

const importGuard = () => import('../../resources/js/build-guard.js');

describe('build-guard', () => {
    it('boots cleanly on a fresh window and records the build marker', async () => {
        await expect(importGuard()).resolves.toBeDefined();

        expect(window.__roundupBuild).toMatch(/build-guard/);
        expect(reload).not.toHaveBeenCalled();
    });

    it('reloads when a pre-guard Alpine survives without a marker (straggler tab)', async () => {
        window.Alpine = { version: '3.x-from-old-build' };

        await expect(importGuard()).rejects.toThrow('pre-guard Alpine');
        expect(reload).toHaveBeenCalledTimes(1);
    });

    it('reloads when a different build marker is already recorded', async () => {
        window.__roundupBuild = '/build/assets/app-OLDHASH.js';

        await expect(importGuard()).rejects.toThrow('build changed');
        expect(reload).toHaveBeenCalledTimes(1);
    });

    it('proceeds when the marker matches this build (same-build re-execution)', async () => {
        await importGuard(); // first execution records the marker
        reload.mockClear();

        vi.resetModules();
        await expect(importGuard()).resolves.toBeDefined();
        expect(reload).not.toHaveBeenCalled();
    });
});
