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
