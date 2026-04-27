import './bootstrap';
import './guest-location';

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

                // When a new controller takes over, reload for fresh content
                navigator.serviceWorker.addEventListener('controllerchange', () => {
                    console.log('[SW] Controller changed — reloading');
                    window.location.reload();
                });
            })
            .catch((err) => {
                console.warn('[SW] Registration failed:', err);
            });
    });
}
