/**
 * PostHog Analytics Initialization
 *
 * Client-side analytics for authenticated and anonymous users.
 * Reads API key and host from server-injected <meta> tags.
 *
 * Key design decisions:
 * - Gated behind cookie consent: posthog.init() only fires when analytics consent is granted
 * - Listens for cookieConsentChanged event so consent after page load activates tracking
 * - captureHistoryEvents: false — uses livewire:navigated hook instead for correct page titles
 * - PII is set server-side only; client-side identify() just links the session to user ID
 * - Session replay masks all inputs, images, and [data-ph-mask] elements for GDPR compliance
 *
 * See docs/posthog.md for architecture details.
 */
import posthog from 'posthog-js';

const apiKey = document.querySelector('meta[name="posthog-api-key"]')?.content;
const apiHost = document.querySelector('meta[name="posthog-api-host"]')?.content;

let posthogInitialized = false;

/**
 * Read the cookie_consent cookie and check if a specific category is granted.
 */
function hasConsented(category) {
    const match = document.cookie.match('(^|;)\\s*cookie_consent\\s*=\\s*([^;]+)');
    if (!match) return false;
    try {
        const consent = JSON.parse(decodeURIComponent(match.pop()));
        return consent[category] === true;
    } catch {
        return false;
    }
}

/**
 * Initialize PostHog with all features. Called only once when consent is granted.
 */
function initPostHog() {
    if (posthogInitialized) return;
    if (!apiKey || !apiHost) return;

    posthog.init(apiKey, {
        api_host: apiHost,
        autocapture: true,
        autocaptureExceptions: true,
        capture_pageview: true,
        capture_pageleave: 'if_capture_pageview',
        captureHistoryEvents: false,
        session_recording: {
            maskAllInputs: true,
            maskAllImages: true,
            maskTextSelector: '[data-ph-mask], input[type="password"], input[name="email"], input[name="username"], input[name="phone"], input[name="card_number"], input[name="cvv"], input[name="ssn"]',
            captureLog: false,
            captureNetworkTelemetry: true,
            recordCanvas: false,
            sampleRate: parseFloat(
                document.querySelector('meta[name="posthog-replay-sample-rate"]')?.content || '0'
            ),
        },
        respect_dnt: true,
        advanced_disable_decide: false,
    });

    posthogInitialized = true;

    if (import.meta.env.DEV) {
        console.log('[PostHog] Initialized with host:', apiHost);
    }

    // ── Surveys ──────────────────────────────────────────
    const surveysEnabled = document.querySelector(
        'meta[name="posthog-surveys-enabled"]',
    )?.content === 'true';

    if (surveysEnabled) {
        window.addEventListener('ph:survey:sent', (e) => {
            if (import.meta.env.DEV) console.log('[PostHog] Survey submitted:', e.detail);
        });
        window.addEventListener('ph:survey:shown', (e) => {
            if (import.meta.env.DEV) console.log('[PostHog] Survey shown:', e.detail);
        });
        window.addEventListener('ph:survey:dismissed', (e) => {
            if (import.meta.env.DEV) console.log('[PostHog] Survey dismissed:', e.detail);
        });
    } else {
        const style = document.createElement('style');
        style.textContent = '[data-ph-survey], .ph-survey { display: none !important; }';
        document.head.appendChild(style);
        if (import.meta.env.DEV) console.log('[PostHog] Surveys: disabled (meta tag override)');
    }

    // ── Livewire wire:navigate pageview tracking ─────────
    document.addEventListener('livewire:navigating', () => {
        posthog.capture('$pageleave');
        if (import.meta.env.DEV) console.log('[PostHog] $pageleave (livewire:navigating)');
    });

    document.addEventListener('livewire:navigated', () => {
        posthog.capture('$pageview');
        if (import.meta.env.DEV) console.log('[PostHog] $pageview (livewire:navigated)');
    });

    // ── User identification ──────────────────────────────
    if (window.__posthogUser) {
        posthog.identify(window.__posthogUser.id);
        if (import.meta.env.DEV) console.log('[PostHog] Identified user:', window.__posthogUser.id);
        delete window.__posthogUser;
    }

    // ── Namespaced helpers (avoid polluting global scope) ──
    // Access via window.Roundup.posthog.featureFlag(key) etc.
    window.Roundup = window.Roundup || {};
    window.Roundup.posthog = {
        featureFlag: (key) => {
            if (!posthog.__loaded) return undefined;
            return posthog.getFeatureFlag(key);
        },
        captureError: (error, context = {}) => {
            if (posthog.captureException) {
                posthog.captureException(error, context);
            } else {
                posthog.capture('$exception', {
                    $exception_type: error?.name || 'Error',
                    $exception_message: error?.message || String(error),
                    $exception_stack: error?.stack || '',
                    ...context,
                });
            }
        },
    };
}

/**
 * Try to initialize PostHog if analytics consent is already granted.
 */
function tryInit() {
    if (hasConsented('analytics')) {
        initPostHog();
    }
}

// ── Consent-aware initialization ────────────────────────
// 1. Try immediately (cookie may already be set from a previous visit)
tryInit();

// 2. Listen for consent changes (first-time consent or preference updates)
document.addEventListener('cookieConsentChanged', (e) => {
    tryInit();
});

// ── DNT warning (dev only) ──────────────────────────────
if (import.meta.env.DEV && navigator.doNotTrack === '1') {
    console.warn('[PostHog] Do Not Track is enabled — all tracking is disabled.');
}

export default posthog;
