/**
 * ClientErrors — consent-independent runtime-error fallback beacon.
 *
 * posthog-js (resources/js/posthog.js, autocaptureExceptions) only captures
 * exceptions for users who granted analytics consent; runtime errors from
 * NON-consenting users used to vanish entirely. This module bridges a
 * minimal, PII-free fingerprint of every uncaught error into the server
 * error log (POST /client-errors), where the existing server-side
 * alerting pipeline can see it.
 *
 * Collected (only): truncated message (200 chars), source file URL, line,
 * column, a one-way djb2 digest of the stack string (NEVER the stack
 * itself), and the page URL path. No user identifiers, no localStorage or
 * cookie reads.
 *
 * Fire-and-forget everywhere: client-side rate limit (max 5/min, drop the
 * rest), fetch keepalive, total silence on failure. The `reporting` guard
 * prevents an error thrown by the beacon itself from re-entering the
 * handler — the beacon never reports on itself and never rethrows.
 */

const ENDPOINT = '/client-errors';
const MAX_REPORTS_PER_MINUTE = 5;
const WINDOW_MS = 60 * 1000;
const MAX_MESSAGE_LENGTH = 200;
const MAX_SOURCE_LENGTH = 500;

/** Timestamps (ms) of the reports sent within the current rate-limit window. */
let sentAt = [];
/** Re-entrancy guard: while true, incoming error events are ignored. */
let reporting = false;

function withinBudget(now) {
    sentAt = sentAt.filter((ts) => now - ts < WINDOW_MS);
    if (sentAt.length >= MAX_REPORTS_PER_MINUTE) return false;
    sentAt.push(now);
    return true;
}

/**
 * djb2 one-way hash of the stack string — enough to group identical
 * errors server-side without ever transmitting the stack itself.
 */
function stackDigest(stack) {
    if (typeof stack !== 'string' || stack.length === 0) return '';
    let hash = 5381;
    for (let i = 0; i < stack.length; i += 1) {
        hash = ((hash << 5) + hash + stack.charCodeAt(i)) | 0;
    }
    return (hash >>> 0).toString(16);
}

function truncate(value, maxLength) {
    const text = typeof value === 'string' ? value : String(value ?? '');
    return text.slice(0, maxLength);
}

function coerceCount(value) {
    const count = Number(value);
    return Number.isFinite(count) && count >= 0 ? Math.floor(count) : null;
}

function buildReport({ message, source, line, col, stack }) {
    return {
        message: truncate(message, MAX_MESSAGE_LENGTH),
        file: truncate(source, MAX_SOURCE_LENGTH) || null,
        line: coerceCount(line),
        col: coerceCount(col),
        digest: stackDigest(stack),
        page: truncate(window.location.pathname, MAX_SOURCE_LENGTH),
    };
}

function dispatch(report) {
    try {
        const csrfToken =
            document.querySelector('meta[name="csrf-token"]')?.content || '';
        const pending = fetch(ENDPOINT, {
            method: 'POST',
            keepalive: true,
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': csrfToken,
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify(report),
        });
        // Detached on purpose — fire-and-forget, rejections never surface.
        pending.catch(() => {});
    } catch (_e) {
        // fetch unavailable or blocked — silence is the contract.
    }
}

function tryReport(build) {
    if (reporting) return;
    reporting = true;
    try {
        const report = build();
        if (!report.message) return; // nothing meaningful to send
        if (!withinBudget(Date.now())) return; // flooded — drop
        dispatch(report);
    } catch (_e) {
        // A beacon failure must never become a second (reported) error.
    } finally {
        reporting = false;
    }
}

window.addEventListener('error', (event) => {
    tryReport(() =>
        buildReport({
            message: event.message,
            source: event.filename,
            line: event.lineno,
            col: event.colno,
            stack: event.error?.stack,
        }),
    );
});

window.addEventListener('unhandledrejection', (event) => {
    tryReport(() => {
        const error = event.reason instanceof Error ? event.reason : null;
        return buildReport({
            message: error ? error.message : event.reason,
            source: error?.fileName,
            line: error?.lineNumber,
            col: error?.columnNumber,
            stack: error?.stack,
        });
    });
});
