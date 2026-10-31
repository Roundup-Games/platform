/**
 * TimezoneBeacon — authenticated device-timezone beacon (R089).
 *
 * Mirrors the M009 GuestLocation localStorage pattern with a cookie guard:
 *
 * Storage key : rg_timezone (localStorage — last zone successfully POSTed)
 * Cookie guard: rg_timezone (1 year — survives localStorage clears so a
 *               cleared-storage reload does not re-POST the same value)
 *
 * On load, when window.__timezoneBeacon.enabled is true (injected by the
 * app layout for authenticated sessions), the browser's IANA zone is
 * compared against BOTH stored values; only a change triggers a POST to
 * the rate-limited endpoint. Server-side rules apply on top: no write over
 * a manually-set profile zone, no write when unchanged. Silent degradation
 * everywhere — any failure (no Intl, fetch error, storage disabled) is
 * swallowed and retried on the next load.
 */

const STORAGE_KEY = 'rg_timezone';
const COOKIE_NAME = 'rg_timezone';
const COOKIE_MAX_AGE_S = 365 * 24 * 60 * 60;

function readCookie(name) {
    try {
        const prefix = name + '=';
        const found = document.cookie
            .split('; ')
            .find((entry) => entry.startsWith(prefix));
        return found ? decodeURIComponent(found.slice(prefix.length)) : '';
    } catch (_e) {
        return '';
    }
}

function writeCookie(name, value) {
    try {
        document.cookie =
            name + '=' + encodeURIComponent(value) +
            '; path=/' +
            '; max-age=' + COOKIE_MAX_AGE_S +
            (location.protocol === 'https:' ? '; Secure' : '') +
            '; SameSite=Lax';
    } catch (_e) {
        // Cookies may be blocked — localStorage still guards re-POSTs per device.
    }
}

function markSent(timezone) {
    try {
        localStorage.setItem(STORAGE_KEY, timezone);
    } catch (_e) { /* storage disabled */ }
    writeCookie(COOKIE_NAME, timezone);
}

/**
 * Report the device timezone once when it changed.
 *
 * @returns {Promise<void>}
 */
export async function beaconTimezone() {
    const config = window.__timezoneBeacon;
    if (!config || !config.enabled || !config.url) return;

    let timezone = '';
    try {
        timezone = Intl.DateTimeFormat().resolvedOptions().timeZone || '';
    } catch (_e) {
        return; // no Intl support — nothing to report
    }
    if (!timezone) return;

    // Cookie + localStorage guard: POST only when the value actually changed
    // (cookie survives localStorage clears; localStorage covers cookie caps).
    const lastLocalStorage = (() => {
        try {
            return localStorage.getItem(STORAGE_KEY) || '';
        } catch (_e) {
            return '';
        }
    })();
    const lastCookie = readCookie(COOKIE_NAME);
    if (timezone === lastLocalStorage && timezone === lastCookie) return;

    try {
        const response = await fetch(config.url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN':
                    document.querySelector('meta[name="csrf-token"]')?.content || '',
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify({ timezone }),
        });

        // 204 (and any 2xx) counts as delivered — remember locally so the
        // same zone is never POSTed twice. 429 keeps the guard unmarked and
        // retries on the next load; anything else is ignored silently.
        if (response.ok) {
            markSent(timezone);
        }
    } catch (_e) {
        // Offline/blocked — retry next load.
    }
}

document.addEventListener('DOMContentLoaded', () => {
    beaconTimezone();
});

// Expose on window for console / Blade script-tag usage.
window.TimezoneBeacon = { beaconTimezone };
