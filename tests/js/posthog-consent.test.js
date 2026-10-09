import { beforeEach, describe, expect, it } from 'vitest';
// Importing posthog.js is side-effect-ful but safe under test: with no
// posthog meta tags present, tryInit() finds no consent cookie and the
// lazy init is never reached. The module top level only reads meta tags
// and registers a cookieConsentChanged listener.
import { hasConsented } from '../../resources/js/posthog.js';

function setConsentCookie(value) {
    document.cookie = `cookie_consent=${encodeURIComponent(JSON.stringify(value))}; path=/`;
}

describe('posthog consent gating (hasConsented)', () => {
    beforeEach(() => {
        // Clear any cookie_consent from a previous test.
        document.cookie = 'cookie_consent=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/';
    });

    it('returns false when no consent cookie exists', () => {
        expect(hasConsented('analytics')).toBe(false);
    });

    it('returns true only when the requested category is exactly true', () => {
        setConsentCookie({ analytics: true, marketing: false });
        expect(hasConsented('analytics')).toBe(true);
        expect(hasConsented('marketing')).toBe(false);
    });

    it('returns false for a category missing from the consent object', () => {
        setConsentCookie({ marketing: true });
        expect(hasConsented('analytics')).toBe(false);
    });

    it('rejects truthy non-boolean values (=== true check)', () => {
        setConsentCookie({ analytics: 'true' });
        expect(hasConsented('analytics')).toBe(false);
    });

    it('returns false when the cookie value is malformed JSON', () => {
        document.cookie = `cookie_consent=${encodeURIComponent('{not json')}; path=/`;
        expect(hasConsented('analytics')).toBe(false);
    });
});
