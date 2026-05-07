/**
 * Push Notification Subscription Manager
 *
 * Handles requesting notification permission, subscribing via PushManager,
 * sending the subscription to the server API, and unsubscribing.
 *
 * Usage:
 *   import { initPushSubscriptions } from './push';
 *   // Call after DOM is ready and user is authenticated
 *   initPushSubscriptions();
 */

const API_SUBSCRIBE = '/api/v1/push/subscribe';
const API_UNSUBSCRIBE = '/api/v1/push/subscribe';
const API_VAPID_KEY = '/api/v1/push/vapid-public-key';

/**
 * Check if push notifications are supported and allowed.
 */
function isPushSupported() {
    return 'serviceWorker' in navigator
        && 'PushManager' in window
        && 'Notification' in window;
}

/**
 * Get the current Notification permission state.
 * @returns {'granted'|'denied'|'default'}
 */
export function getPermissionStatus() {
    if (!('Notification' in window)) return 'denied';
    return Notification.permission;
}

/**
 * Request notification permission from the user.
 * @returns {Promise<boolean>} true if granted
 */
export async function requestPermission() {
    if (!('Notification' in window)) return false;

    if (Notification.permission === 'granted') return true;
    if (Notification.permission === 'denied') return false;

    const result = await Notification.requestPermission();
    return result === 'granted';
}

/**
 * Fetch the VAPID public key from the server.
 * @returns {Promise<string|null>}
 */
async function fetchVapidKey() {
    try {
        const resp = await fetch(API_VAPID_KEY, {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
        });

        if (!resp.ok) {
            console.warn('[Push] VAPID key endpoint returned', resp.status);
            return null;
        }

        const data = await resp.json();
        return data.public_key || null;
    } catch (err) {
        console.warn('[Push] Failed to fetch VAPID key:', err);
        return null;
    }
}

/**
 * Get the current push subscription from the service worker registration.
 * @returns {Promise<PushSubscription|null>}
 */
async function getSWRegistration() {
    const registration = await navigator.serviceWorker.ready;
    return registration;
}

/**
 * Subscribe the browser to push notifications and register with the server.
 *
 * @returns {Promise<{success: boolean, id?: number, error?: string}>}
 */
export async function subscribeToPush() {
    if (!isPushSupported()) {
        return { success: false, error: 'Push notifications are not supported in this browser.' };
    }

    const permitted = await requestPermission();
    if (!permitted) {
        return { success: false, error: 'Notification permission was not granted.' };
    }

    const registration = await getSWRegistration();
    const existingSub = await registration.pushManager.getSubscription();
    if (existingSub) {
        // Already subscribed — sync with server
        return syncSubscription(existingSub);
    }

    const vapidKey = await fetchVapidKey();
    if (!vapidKey) {
        return { success: false, error: 'Push notifications are not configured on the server.' };
    }

    const applicationServerKey = urlBase64ToUint8Array(vapidKey);
    const subscription = await registration.pushManager.subscribe({
        userVisibleOnly: true,
        applicationServerKey,
    });

    return syncSubscription(subscription);
}

/**
 * Send (or re-send) a PushSubscription to the server.
 */
async function syncSubscription(subscription) {
    const payload = subscription.toJSON();

    try {
        const resp = await fetch(API_SUBSCRIBE, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
            },
            body: JSON.stringify({
                endpoint: payload.endpoint,
                keys: {
                    // Project uses 'p256h' as a short name for the DB column and API
                    // payload. The server's PushChannel maps this to 'p256dh' when
                    // constructing Minishlink Subscription objects (the Web Push standard).
                    p256h: payload.keys?.p256dh || '',
                    auth: payload.keys?.auth || '',
                },
            }),
        });

        if (!resp.ok) {
            const body = await resp.json().catch(() => ({}));
            console.warn('[Push] Subscribe API returned', resp.status, body);
            return { success: false, error: body.message || 'Server error.' };
        }

        const data = await resp.json();
        console.log('[Push] Subscription synced with server, id:', data.id);
        return { success: true, id: data.id };
    } catch (err) {
        console.warn('[Push] Failed to sync subscription:', err);
        return { success: false, error: 'Network error.' };
    }
}

/**
 * Unsubscribe from push notifications and notify the server.
 *
 * @returns {Promise<{success: boolean, error?: string}>}
 */
export async function unsubscribeFromPush() {
    if (!isPushSupported()) {
        return { success: false, error: 'Push notifications are not supported.' };
    }

    try {
        const registration = await getSWRegistration();
        const subscription = await registration.pushManager.getSubscription();

        if (!subscription) {
            return { success: true }; // Already unsubscribed
        }

        const endpoint = subscription.endpoint;

        // Unsubscribe from the push service first
        await subscription.unsubscribe();

        // Notify the server
        const resp = await fetch(API_UNSUBSCRIBE, {
            method: 'DELETE',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
            },
            body: JSON.stringify({ endpoint }),
        });

        if (!resp.ok && resp.status !== 404) {
            console.warn('[Push] Unsubscribe API returned', resp.status);
        }

        console.log('[Push] Unsubscribed successfully');
        return { success: true };
    } catch (err) {
        console.warn('[Push] Failed to unsubscribe:', err);
        return { success: false, error: 'Unsubscribe failed.' };
    }
}

/**
 * Initialize push subscription UI bindings.
 *
 * Finds elements with data-push="subscribe" and data-push="unsubscribe" and
 * attaches click handlers. Also updates UI state on page load.
 */
export function initPushSubscriptions() {
    if (!isPushSupported()) {
        updateUIState('unsupported');
        return;
    }

    // Attach click handlers
    document.querySelectorAll('[data-push="subscribe"]').forEach((el) => {
        el.addEventListener('click', async (e) => {
            e.preventDefault();
            el.disabled = true;
            const result = await subscribeToPush();
            el.disabled = false;

            if (result.success) {
                updateUIState('subscribed');
            } else if (result.error) {
                updateUIState(getPermissionStatus() === 'denied' ? 'denied' : 'default');
            }
        });
    });

    document.querySelectorAll('[data-push="unsubscribe"]').forEach((el) => {
        el.addEventListener('click', async (e) => {
            e.preventDefault();
            el.disabled = true;
            const result = await unsubscribeFromPush();
            el.disabled = false;

            if (result.success) {
                updateUIState('default');
            }
        });
    });

    // Set initial UI state
    const perm = getPermissionStatus();
    if (perm === 'denied') {
        updateUIState('denied');
    } else {
        // Check if already subscribed
        getSWRegistration()
            .then((reg) => reg.pushManager.getSubscription())
            .then((sub) => {
                updateUIState(sub ? 'subscribed' : 'default');
            })
            .catch(() => updateUIState('default'));
    }
}

/**
 * Update data attributes on the body to reflect push subscription state.
 * Also toggle visibility of push subscription UI elements in profile settings.
 *
 * States: 'default' | 'subscribed' | 'denied' | 'unsupported'
 */
function updateUIState(state) {
    document.body.dataset.pushState = state;

    // Toggle push subscription management UI in profile settings
    document.querySelectorAll('[data-push-ui]').forEach((el) => {
        if (el.dataset.pushUi === state) {
            el.classList.remove('hidden');
        } else {
            el.classList.add('hidden');
        }
    });
}

/**
 * Convert a base64-encoded VAPID key to a Uint8Array for PushManager.
 */
function urlBase64ToUint8Array(base64String) {
    const padding = '='.repeat((4 - (base64String.length % 4)) % 4);
    const base64 = (base64String + padding)
        .replace(/-/g, '+')
        .replace(/_/g, '/');

    const rawData = atob(base64);
    const outputArray = new Uint8Array(rawData.length);

    for (let i = 0; i < rawData.length; ++i) {
        outputArray[i] = rawData.charCodeAt(i);
    }

    return outputArray;
}
