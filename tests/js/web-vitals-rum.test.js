import { describe, expect, it, vi, beforeEach } from 'vitest';

// web-vitals-rum.js imports the real web-vitals package; mocking the on*
// subscriptions keeps these tests deterministic (no browser timing needed)
// while still exercising our report wiring.
vi.mock('web-vitals', () => ({
    onLCP: vi.fn(),
    onINP: vi.fn(),
    onCLS: vi.fn(),
    onTTFB: vi.fn(),
}));

import { initWebVitalsRum, classifyPath } from '../../resources/js/web-vitals-rum.js';
import { onCLS, onINP, onLCP, onTTFB } from 'web-vitals';

describe('classifyPath — cwv.php-aligned template keys', () => {
    it('maps the tracked template surfaces', () => {
        expect(classifyPath('/')).toEqual({ template: 'home', locale: 'en' });
        expect(classifyPath('/de')).toEqual({ template: 'home', locale: 'de' });
        expect(classifyPath('/en/discover/board-games?x=1')).toEqual({ template: 'discovery-board-games', locale: 'en' });
        expect(classifyPath('/de/discover/adventures')).toEqual({ template: 'discovery-adventures', locale: 'de' });
        expect(classifyPath('/game-systems')).toEqual({ template: 'game-systems-index', locale: 'en' });
        expect(classifyPath('/de/game-systems/carcassonne')).toEqual({ template: 'game-system-detail', locale: 'de' });
        expect(classifyPath('/en/games/some-uuid')).toEqual({ template: 'game-detail', locale: 'en' });
        expect(classifyPath('/campaigns/abc')).toEqual({ template: 'campaign-detail', locale: 'en' });
        expect(classifyPath('/de/gms')).toEqual({ template: 'gm-directory', locale: 'de' });
        expect(classifyPath('/u/vasile-burghelea')).toEqual({ template: 'profile-public', locale: 'en' });
        expect(classifyPath('/de/venue/spielwiese')).toEqual({ template: 'venue-detail', locale: 'de' });
        expect(classifyPath('/cities')).toEqual({ template: 'city-hubs-index', locale: 'en' });
        expect(classifyPath('/de/cities/berlin')).toEqual({ template: 'city-hub', locale: 'de' });
        expect(classifyPath('/for-organizers')).toEqual({ template: 'static-for-organizers', locale: 'en' });
    });

    it('falls back to a namespaced segment instead of an empty key', () => {
        expect(classifyPath('/en/some-future-page')).toEqual({ template: 'other-some-future-page', locale: 'en' });
    });
});

describe('initWebVitalsRum — reporting wiring', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('subscribes all four metrics and reports through the capture callback', () => {
        const capture = vi.fn();
        window.history.replaceState({}, '', '/de/game-systems/carcassonne');

        initWebVitalsRum(capture);

        for (const subscribe of [onLCP, onINP, onCLS, onTTFB]) {
            expect(subscribe).toHaveBeenCalledTimes(1);
            // Simulate the metric callback web-vitals would invoke.
            subscribe.mock.calls[0][0]({ name: subscribe === onCLS ? 'CLS' : 'LCP', value: subscribe === onCLS ? 0.069 : 1800, rating: 'good' });
        }

        expect(capture).toHaveBeenCalledWith('web_vitals', {
            metric: 'LCP',
            value: 1800,
            rating: 'good',
            template: 'game-system-detail',
            locale: 'de',
            path: '/de/game-systems/carcassonne',
        });
        expect(capture).toHaveBeenCalledWith('web_vitals', expect.objectContaining({ metric: 'CLS', value: 0.069 }));
    });

    it('is a no-op without a capture function', () => {
        expect(() => initWebVitalsRum(undefined)).not.toThrow();
        expect(onLCP).not.toHaveBeenCalled();
    });
});
