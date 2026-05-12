/**
 * HTTP client setup using native fetch.
 *
 * Replaces the previous axios dependency. Livewire handles its own XHR,
 * so this thin wrapper only covers CSRF header injection for any custom
 * fetch() calls made by app code.
 */

// Expose a convenience wrapper that injects CSRF + JSON headers.
window.httpFetch = async function (url, options = {}) {
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;

    const headers = {
        'X-Requested-With': 'XMLHttpRequest',
        ...(csrfToken ? { 'X-CSRF-TOKEN': csrfToken } : {}),
        ...(options.headers || {}),
    };

    // Auto-set Content-Type for JSON bodies when not already specified
    if (options.body && typeof options.body === 'object' && !(options.body instanceof FormData)) {
        headers['Content-Type'] = headers['Content-Type'] || 'application/json';
        options.body = JSON.stringify(options.body);
    }

    return fetch(url, { ...options, headers });
};
