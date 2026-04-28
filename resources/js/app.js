import './bootstrap';
import './guest-location';
import { initPushSubscriptions } from './push';

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
            if (hash && ['profile', 'preferences', 'privacy', 'notifications', 'account'].includes(hash)) {
                this.activeTab = hash;
            }
            this.$watch('activeTab', (val) => { window.location.hash = val; });
        },
        setTab(tab) {
            this.activeTab = tab;
        }
    }));
});

// ── Service Worker Registration ───────────────────────────────────────────────
if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js', { scope: '/' })
            .then((registration) => {
                console.log('[SW] Registered:', registration.scope);

                // Detect updates and notify the user
                registration.addEventListener('updatefound', () => {
                    const newWorker = registration.installing;
                    newWorker.addEventListener('statechange', () => {
                        if (newWorker.state === 'installed' && navigator.serviceWorker.controller) {
                            // New version available — auto-activate it
                            console.log('[SW] New version available — activating');
                            newWorker.postMessage({ type: 'SKIP_WAITING' });
                        }
                    });
                });

                // When a new controller takes over, show an update toast
                navigator.serviceWorker.addEventListener('controllerchange', () => {
                    console.log('[SW] Controller changed — showing update toast');
                    showUpdateToast();
                });
            })
            .catch((err) => {
                console.warn('[SW] Registration failed:', err);
            });
    });
}

// ── SW Update Toast ───────────────────────────────────────────────────────────
// Shown when a new service worker takes control (deploy-time update).
// Reads localized strings from window.__pwaUpdateToast injected by Blade,
// falling back to English defaults when rendered outside a Blade template.
function showUpdateToast() {
    // Prevent duplicate toasts
    if (document.getElementById('sw-update-toast')) return;

    const i18n = window.__pwaUpdateToast || {};
    const message = i18n.message || 'A new version is available';
    const action = i18n.action || 'Update';
    const icon = 'system_update';

    const toast = document.createElement('div');
    toast.id = 'sw-update-toast';
    toast.setAttribute('role', 'status');
    toast.setAttribute('aria-live', 'polite');
    toast.className = 'fixed bottom-4 right-4 z-50 flex items-center gap-3 px-5 py-3 rounded-xl shadow-lg bg-primary text-on-primary pointer-events-auto';

    toast.innerHTML =
        '<span class="material-symbols-outlined text-lg" aria-hidden="true">' + icon + '</span>' +
        '<span class="text-sm font-medium">' + message + '</span>' +
        '<button id="sw-update-action" class="underline font-semibold text-sm hover:opacity-80 transition-opacity">' + action + '</button>' +
        '<button id="sw-update-dismiss" class="ml-1 text-lg leading-none hover:opacity-80 transition-opacity" aria-label="Dismiss">&times;</button>';

    document.body.appendChild(toast);

    // Reload on action
    document.getElementById('sw-update-action').addEventListener('click', () => {
        window.location.reload();
    });

    // Dismiss
    document.getElementById('sw-update-dismiss').addEventListener('click', () => {
        toast.remove();
    });

    // Auto-dismiss after 30 seconds
    setTimeout(() => {
        if (toast.parentNode) toast.remove();
    }, 30000);
}
