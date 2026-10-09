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
            signal: AbortSignal.timeout(15_000),
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

    // Kick off the VAPID key fetch immediately — it's independent of the
    // permission prompt and SW registration, and previously waited behind
    // them (a slow permission dialog delayed it for nothing).
    const vapidKeyPromise = fetchVapidKey();

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

    const vapidKey = await vapidKeyPromise;
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
 * Remap Web Push standard key names to the short names the project API uses.
 *
 * The server's PushChannel maps 'p256h' back to 'p256dh' when constructing
 * Minishlink Subscription objects (the Web Push standard).
 *
 * @param {{ p256dh?: string, auth?: string }|undefined} keys
 * @returns {{ p256h: string, auth: string }}
 */
export function toApiKeys(keys) {
    return {
        p256h: keys?.p256dh || '',
        auth: keys?.auth || '',
    };
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
            signal: AbortSignal.timeout(15_000),
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
            },
            body: JSON.stringify({
                endpoint: payload.endpoint,
                keys: toApiKeys(payload.keys),
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
            signal: AbortSignal.timeout(15_000),
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
 * Initialize push subscription UI bindings (morph-proof).
 *
 * The [data-push] buttons live inside a Livewire component whose DOM is
 * replaced on morph, which orphans per-element listeners. We therefore bind a
 * single document-level click listener (event delegation): it survives morphs
 * and repeated initPushSubscriptions() calls are no-ops.
 */
let delegationBound = false;

export function initPushSubscriptions() {
    if (!isPushSupported()) {
        updateUIState('unsupported');
        return;
    }

    if (!delegationBound) {
        delegationBound = true;
        document.addEventListener('click', onPushTriggerClick);
    }

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
 * Delegated click handler for [data-push="subscribe"] / [data-push="unsubscribe"].
 * @param {MouseEvent} event
 */
async function onPushTriggerClick(event) {
    const trigger = event.target.closest('[data-push]');
    if (!trigger) return;

    const mode = trigger.dataset.push;
    if (mode !== 'subscribe' && mode !== 'unsubscribe') return;

    event.preventDefault();
    trigger.disabled = true;
    const result = mode === 'subscribe' ? await subscribeToPush() : await unsubscribeFromPush();
    trigger.disabled = false;

    if (mode === 'subscribe') {
        if (result.success) {
            updateUIState('subscribed');
        } else if (result.error) {
            updateUIState(getPermissionStatus() === 'denied' ? 'denied' : 'default');
        }
    } else if (result.success) {
        updateUIState('default');
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
 * Convert a base64url-encoded VAPID key to a Uint8Array for PushManager.
 *
 * @param {string} base64String Base64url (RFC 4648 §5) encoded key, padding optional.
 * @returns {Uint8Array}
 */
export function urlBase64ToUint8Array(base64String) {
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
