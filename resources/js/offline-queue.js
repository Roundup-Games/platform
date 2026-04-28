/**
 * Offline Action Queue for Background Sync.
 *
 * Provides two code paths for offline form submissions:
 * 1. Background Sync API (Chrome/Edge): Registers a sync tag with the service worker,
 *    which replays queued actions when connectivity is restored.
 * 2. Fallback (all other browsers): Shows a toast notification telling the user
 *    their action will complete when they reconnect.
 */

const SYNC_TAG = 'roundup-offline-actions';

/**
 * Queue an action for later replay via Background Sync.
 * @param {{ url: string, method?: string, body?: string, headers?: object }} action
 * @returns {Promise<boolean>} true if queued via sync, false if fallback toast shown
 */
export async function queueAction(action) {
    if (!('serviceWorker' in navigator)) {
        showOfflineToast();
        return false;
    }

    try {
        const registration = await navigator.serviceWorker.ready;

        if (!registration.active) {
            showOfflineToast();
            return false;
        }

        // Send action to SW and wait for confirmation before registering sync
        await new Promise((resolve, reject) => {
            const messageChannel = new MessageChannel();
            messageChannel.port1.onmessage = (event) => {
                if (event.data && event.data.type === 'OFFLINE_ACTION_QUEUED') {
                    resolve();
                } else {
                    reject(new Error('Unexpected response from service worker'));
                }
            };
            messageChannel.port1.onmessageerror = () => {
                reject(new Error('Message port error'));
            };

            registration.active.postMessage(
                { type: 'QUEUE_OFFLINE_ACTION', payload: action },
                [messageChannel.port2]
            );

            // Timeout after 3 seconds — SW might not respond
            setTimeout(() => reject(new Error('SW response timeout')), 3000);
        });

        // Action is stored in IndexedDB — now register Background Sync
        if ('SyncManager' in registration) {
            await registration.sync.register(SYNC_TAG);
            showQueuedToast();
            return true;
        }

        // No Background Sync — show fallback toast
        showOfflineToast();
        return false;
    } catch (error) {
        console.warn('[OfflineQueue] Failed to queue action:', error);
        showOfflineToast();
        return false;
    }
}

/**
 * Show a toast when an action has been queued for Background Sync.
 */
export function showQueuedToast() {
    const i18n = window.__pwaOfflineToast || {};
    const message = i18n.queued || 'Action queued — will complete when you reconnect';
    showToast(message, 'cloud_sync');
}

/**
 * Show a toast when offline and Background Sync is not available.
 */
export function showOfflineToast() {
    const i18n = window.__pwaOfflineToast || {};
    const message = i18n.offline || 'You\'re offline — connect to complete this action';
    showToast(message, 'cloud_off');
}

function showToast(message, icon) {
    // Remove any existing toast
    const existing = document.getElementById('offline-action-toast');
    if (existing) existing.remove();

    const toast = document.createElement('div');
    toast.id = 'offline-action-toast';
    toast.setAttribute('role', 'status');
    toast.setAttribute('aria-live', 'polite');
    toast.className = 'fixed bottom-4 left-4 right-4 sm:left-auto sm:right-4 sm:w-96 z-50 flex items-center gap-3 px-4 py-3 rounded-xl shadow-lg bg-surface text-on-surface border border-outline-variant';

    toast.innerHTML =
        '<span class="material-symbols-outlined text-lg text-primary" aria-hidden="true">' + icon + '</span>' +
        '<span class="text-sm flex-1">' + message + '</span>' +
        '<button class="text-on-surface-variant text-lg leading-none hover:text-on-surface transition-colors" aria-label="Dismiss" onclick="this.parentElement.remove()">&times;</button>';

    document.body.appendChild(toast);
    setTimeout(() => { if (toast.parentNode) toast.remove(); }, 5000);
}
