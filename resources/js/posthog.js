/**
 * PostHog Analytics Initialization
 *
 * Client-side analytics for authenticated and anonymous users.
 * Reads API key and host from server-injected <meta> tags.
 *
 * Key design decisions:
 * - captureHistoryEvents: false — uses livewire:navigated hook instead for correct page titles
 * - PII is set server-side only; client-side identify() just links the session to user ID
 * - Session replay masks all inputs, images, and [data-ph-mask] elements for GDPR compliance
 *
 * See docs/posthog.md for architecture details.
 */
import posthog from 'posthog-js';

const apiKey = document.querySelector('meta[name="posthog-api-key"]')?.content;
const apiHost = document.querySelector('meta[name="posthog-api-host"]')?.content;

if (apiKey && apiHost) {
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

    if (import.meta.env.DEV && navigator.doNotTrack === '1') {
        console.warn('[PostHog] Do Not Track is enabled — all tracking is disabled.');
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

    if (import.meta.env.DEV) {
        console.log('[PostHog] Initialized with host:', apiHost);
    }
} else {
    if (import.meta.env.DEV) {
        console.log('[PostHog] Skipped — missing meta tags (api_key or api_host)');
    }
}

export default posthog;
