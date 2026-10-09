import './bootstrap';
import './guest-location';
import './image-fallback';
import { initPushSubscriptions } from './push';
import { showOfflineToast as showOfflineActionToast, showToast } from './toast';

// ── Offline indicator immediate bridge ────────────────────────────────────────
// Ensures the offline indicator is visible before Alpine bootstraps.
// The Alpine component (offlineIndicator) takes over once initialized.
document.addEventListener('DOMContentLoaded', () => {
    const indicator = document.querySelector('[x-data="offlineIndicator()"]');
    if (!indicator) return;

    function applyState() {
        indicator.setAttribute('data-network', navigator.onLine ? 'online' : 'offline');
    }

    applyState();
    window.addEventListener('offline', applyState);
    window.addEventListener('online', applyState);

    // Initialize push subscription UI bindings
    initPushSubscriptions();
});

// Alpine is provided by Livewire v3 (livewire/livewire) — no separate import needed.
// If you need to register Alpine components or stores, use:
//   document.addEventListener('alpine:init', () => { Alpine.data(...) })

document.addEventListener('alpine:init', () => {
    Alpine.data('profileTabs', () => ({
        activeTab: 'profile',
        init() {
            const hash = window.location.hash?.slice(1);
            if (hash && ['profile', 'preferences', 'gm_profile'].includes(hash)) {
                this.activeTab = hash;
            }
            this.$watch('activeTab', (val) => { window.location.hash = val; });
        },
        setTab(tab) {
            this.activeTab = tab;
        }
    }));

    Alpine.data('settingsTabs', () => ({
        activeTab: 'privacy',
        init() {
            const hash = window.location.hash?.slice(1);
            if (hash && ['privacy', 'notifications', 'account'].includes(hash)) {
                this.activeTab = hash;
            }
            this.$watch('activeTab', (val) => { window.location.hash = val; });
        },
        setTab(tab) {
            this.activeTab = tab;
        }
    }));
});

// ── Offline Action Interception ─────────────────────────────────────────────
// When a Livewire request fails because the user is offline, show a clear message
// instead of a raw error.
document.addEventListener('livewire:init', () => {
    Livewire.hook('request', ({ fail }) => {
        fail(({ error }) => {
            if (!navigator.onLine) {
                showOfflineActionToast();
            }
        });
    });
});

// ── Service Worker Registration ───────────────────────────────────────────────
let swRegistration = null;

if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js', { scope: '/' })
            .then((registration) => {
                console.log('[SW] Registered:', registration.scope);
                swRegistration = registration;

                // Detect updates — show toast FIRST, let user decide when to activate
                registration.addEventListener('updatefound', () => {
                    const newWorker = registration.installing;
                    newWorker.addEventListener('statechange', () => {
                        if (newWorker.state === 'installed' && navigator.serviceWorker.controller) {
                            console.log('[SW] New version downloaded — showing update prompt');
                            showUpdateToast(newWorker);
                        }
                    });
                });
            })
            .catch((err) => {
                console.warn('[SW] Registration failed:', err);
            });
    });
}

// ── SW Update Toast ───────────────────────────────────────────────────────────
// Shown when a new service worker has finished installing.
// The user must explicitly click "Update" — we do NOT auto-send SKIP_WAITING.
// Reads localized strings from window.__pwaUpdateToast injected by Blade,
// falling back to English defaults when rendered outside a Blade template.
function showUpdateToast(waitingWorker) {
    // Prevent duplicate toasts
    if (document.getElementById('sw-update-toast')) return;

    const i18n = window.__pwaUpdateToast || {};
    const message = i18n.message || 'A new version is available';
    const action = i18n.action || 'Update';

    showToast({
        id: 'sw-update-toast',
        message,
        icon: 'system_update',
        variant: 'primary',
        actionLabel: action,
        ttlMs: 30000,
        onAction: (toast) => {
            // Send SKIP_WAITING on user action, then reload once the new
            // worker takes control. The { once: true } listener plus the
            // synchronously disabled button guarantee a single reload.
            const actionButton = toast.querySelector('[data-toast-action]');
            actionButton.disabled = true;
            actionButton.textContent = '…';
            navigator.serviceWorker.addEventListener('controllerchange', () => {
                window.location.reload();
            }, { once: true });
            waitingWorker.postMessage({ type: 'SKIP_WAITING' });
        },
    });
}
