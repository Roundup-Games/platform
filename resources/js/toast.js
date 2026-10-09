/**
 * Shared toast notifications.
 *
 * Single implementation for all app toasts (offline warning, PWA update
 * prompt, future callers). Replaces the hand-rolled copies that previously
 * lived in the deleted offline action queue module and app.js.
 *
 * Features:
 * - role="status" + aria-live="polite" for screen-reader announcements
 * - dedupe by DOM id (a second toast with the same id replaces the first)
 * - dismiss button + auto-dismiss timer (timer is cancelled by manual
 *   dismissal or by triggering the action, so a pending action is never
 *   interrupted by the toast vanishing)
 * - two visual variants matching the original implementations:
 *   'surface'  — neutral card, full-width on mobile (offline toast style)
 *   'primary'  — accent card, bottom-right (update prompt style)
 */

const DEFAULT_TTL_MS = 5000;

const CONTAINER_BASE = 'fixed bottom-4 z-50 flex items-center gap-3 rounded-xl shadow-lg pointer-events-auto';

const VARIANTS = {
    surface: {
        container: `${CONTAINER_BASE} left-4 right-4 sm:left-auto sm:right-4 sm:w-96 px-4 py-3 bg-surface text-on-surface border border-outline-variant`,
        icon: 'material-symbols-outlined text-lg text-primary',
        action: 'underline font-semibold text-sm hover:opacity-80 transition-opacity',
    },
    primary: {
        container: `${CONTAINER_BASE} right-4 px-5 py-3 bg-primary text-on-primary`,
        icon: 'material-symbols-outlined text-lg',
        action: 'underline font-semibold text-sm hover:opacity-80 transition-opacity',
    },
};

/**
 * Render a toast and append it to document.body.
 *
 * @param {object} options
 * @param {string} options.id DOM id; also used for dedupe (replaces an existing toast with the same id).
 * @param {string} options.message Text to display (inserted via textContent — never HTML).
 * @param {string} [options.icon] Material Symbols icon name; omit for no icon.
 * @param {'surface'|'primary'} [options.variant='surface'] Visual style.
 * @param {string} [options.actionLabel] When set, renders an action button.
 * @param {(toast: HTMLDivElement) => void} [options.onAction] Invoked with the toast element on action click.
 * @param {number} [options.ttlMs=5000] Auto-dismiss delay in ms; 0 disables auto-dismiss.
 * @returns {HTMLDivElement} The toast element (removed from the DOM after dismissal).
 */
export function showToast({ id, message, icon, variant = 'surface', actionLabel, onAction, ttlMs = DEFAULT_TTL_MS }) {
    const styles = VARIANTS[variant] ?? VARIANTS.surface;

    // Dedupe: replace any existing toast with the same id.
    const existing = document.getElementById(id);
    if (existing) existing.remove();

    const toast = document.createElement('div');
    toast.id = id;
    toast.setAttribute('role', 'status');
    toast.setAttribute('aria-live', 'polite');
    toast.className = styles.container;

    if (icon) {
        const iconEl = document.createElement('span');
        iconEl.className = styles.icon;
        iconEl.setAttribute('aria-hidden', 'true');
        iconEl.textContent = icon;
        toast.appendChild(iconEl);
    }

    const messageEl = document.createElement('span');
    messageEl.className = variant === 'surface' ? 'text-sm flex-1' : 'text-sm font-medium';
    messageEl.textContent = message;
    toast.appendChild(messageEl);

    let autoDismissTimer = null;
    const cancelAutoDismiss = () => {
        if (autoDismissTimer !== null) {
            clearTimeout(autoDismissTimer);
            autoDismissTimer = null;
        }
    };

    if (actionLabel) {
        const actionButton = document.createElement('button');
        actionButton.dataset.toastAction = '';
        actionButton.className = styles.action;
        actionButton.textContent = actionLabel;
        actionButton.addEventListener('click', () => {
            cancelAutoDismiss();
            onAction?.(toast);
        });
        toast.appendChild(actionButton);
    }

    const dismissButton = document.createElement('button');
    dismissButton.dataset.toastDismiss = '';
    dismissButton.className = variant === 'surface'
        ? 'text-on-surface-variant text-lg leading-none hover:text-on-surface transition-colors'
        : 'ml-1 text-lg leading-none hover:opacity-80 transition-opacity';
    dismissButton.setAttribute('aria-label', 'Dismiss');
    dismissButton.textContent = '\u00d7';
    dismissButton.addEventListener('click', () => {
        cancelAutoDismiss();
        toast.remove();
    });
    toast.appendChild(dismissButton);

    document.body.appendChild(toast);

    if (ttlMs > 0) {
        autoDismissTimer = setTimeout(() => {
            autoDismissTimer = null;
            toast.remove();
        }, ttlMs);
    }

    return toast;
}

/**
 * Show a toast when a Livewire request fails because the user is offline.
 * Reads localized strings from window.__pwaOfflineToast injected by Blade.
 */
export function showOfflineToast() {
    const i18n = window.__pwaOfflineToast || {};
    const message = i18n.offline || 'You\'re offline — connect to complete this action';
    showToast({ id: 'offline-action-toast', message, icon: 'cloud_off', variant: 'surface', ttlMs: 5000 });
}
