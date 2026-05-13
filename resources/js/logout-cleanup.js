/**
 * Logout cleanup — clears the offline action queue on logout.
 *
 * Queued actions belong to the authenticated session. If a different user
 * logs in on the same device, we don't want to replay the previous user's
 * pending requests. Fires fire-and-forget so it never blocks the form POST.
 */
import { clearQueue } from './offline-queue';

document.addEventListener('submit', (event) => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement)) return;

    const action = form.getAttribute('action');
    if (!action) return;

    // Match any form posting to the named logout route.
    // Laravel generates the URL at render time, so we check the path suffix.
    try {
        const url = new URL(action, window.location.origin);
        if (url.pathname === '/logout' && form.method.toUpperCase() === 'POST') {
            clearQueue(); // fire-and-forget — don't await
        }
    } catch {
        // Invalid URL — ignore
    }
});
