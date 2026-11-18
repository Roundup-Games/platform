/**
 * RoundupGames Service Worker
 *
 * Caching strategies:
 *  - Static assets (hashed JS/CSS, icons, manifest): cache-first
 *  - HTML/page requests: network-first (3 s timeout)
 *  - Public API (/api/v1/geocode): stale-while-revalidate
 *  - Other API requests (/api/*): network-only
 *  - Cross-origin requests: bypass
 */

/**
 * Dynamic cache name derived from the Vite manifest content hash.
 * Changes automatically when a new build is deployed, so old cached assets
 * are evicted on activate instead of accumulating indefinitely.
 *
 * Fallback 'roundup-static' is used when the manifest is unreachable (e.g. dev).
 */
let CACHE_NAME = 'roundup-static';

/** Paths to pre-cache on install. Vite assets are read from the manifest. */
const PRE_CACHE_URLS = [
    '/manifest.json',
    '/icons/pwa-192x192.png',
    '/icons/pwa-512x512.png',
    '/offline.html',
    // Self-hosted fonts (latin + latin-ext for en/de coverage)
    '/fonts/inter-latin-var.woff2',
    '/fonts/inter-latin-ext-var.woff2',
    '/fonts/noto-serif-latin-var.woff2',
    '/fonts/noto-serif-latin-ext-var.woff2',
    '/fonts/noto-serif-italic-latin-var.woff2',
    '/fonts/noto-serif-italic-latin-ext-var.woff2',
];

/**
 * Build the full pre-cache list by merging static paths with Vite-hashed assets.
 * Also computes CACHE_NAME from the manifest content so it changes per-build.
 * Runs at install time so it always reflects the latest build.
 */
async function buildPreCacheList() {
    const urls = [...PRE_CACHE_URLS];

    try {
        const resp = await fetch('/build/manifest.json', { cache: 'no-store' });
        if (resp.ok) {
            const text = await resp.text();

            // Derive cache name from manifest content hash (djb2 variant)
            let hash = 0;
            for (let i = 0; i < text.length; i++) {
                hash = ((hash << 5) - hash) + text.charCodeAt(i);
                hash |= 0; // 32-bit integer
            }
            CACHE_NAME = 'roundup-' + Math.abs(hash).toString(36);

            const manifest = JSON.parse(text);
            // posthog chunk ships as assets/posthog-<hash>.js — match on the
            // file segment, not the anchored path start.
            const excluded = [/admin-.*\.css$/, /(^|\/)posthog-[^/]*\.js$/];
            for (const entry of Object.values(manifest)) {
                if (!entry.file) continue;
                // Skip assets the current visitor cannot use: the Filament admin
                // stylesheet (public visitors never load it) and the PostHog SDK
                // chunk (fetched on demand once analytics consent is granted —
                // precaching it would ship it to non-consenting visitors).
                if (excluded.some((re) => re.test(entry.file))) continue;
                urls.push('/build/' + entry.file);
                // Include CSS/JS imports if present
                if (entry.css) {
                    for (const css of entry.css) {
                        urls.push('/build/' + css);
                    }
                }
            }
        }
    } catch (e) {
        console.warn('[SW] Could not fetch Vite manifest for pre-caching:', e);
    }

    return urls;
}

// ── Install ──────────────────────────────────────────────────────────────────
self.addEventListener('install', (event) => {
    console.log('[SW] Install');
    event.waitUntil(
        buildPreCacheList().then((urls) =>
            caches.open(CACHE_NAME).then((cache) => {
                console.log('[SW] Pre-caching', urls.length, 'URLs');
                return cache.addAll(urls);
            })
        ).then(() => self.skipWaiting())
    );
});

// ── Activate ─────────────────────────────────────────────────────────────────
self.addEventListener('activate', (event) => {
    console.log('[SW] Activate');
    event.waitUntil(
        caches.keys().then((names) =>
            Promise.all(
                names
                    .filter((name) => name !== CACHE_NAME)
                    .map((name) => {
                        console.log('[SW] Removing old cache:', name);
                        return caches.delete(name);
                    })
            )
        ).then(() => self.clients.claim())
    );
});

// ── Fetch ────────────────────────────────────────────────────────────────────
const HASHED_ASSET_RE = /-[a-zA-Z0-9]{6,}\.(js|css|woff2?|ttf|otf|png|jpg|jpeg|svg|gif|webp|ico)$/;
const STATIC_EXT_RE = /\.(js|css|woff2?|ttf|otf|png|jpg|jpeg|svg|gif|webp|ico|json)$/;

self.addEventListener('fetch', (event) => {
    const { request } = event;
    const url = new URL(request.url);

    // Skip cross-origin
    if (url.origin !== self.location.origin) return;

    // API requests — network only (never cache authenticated responses)
    if (url.pathname.startsWith('/api/')) {
        // Exception: geocode is an unauthenticated public endpoint.
        // Stale-while-revalidate: serve cached response immediately, update cache in background.
        if (url.pathname === '/api/v1/geocode') {
            event.respondWith(staleWhileRevalidate(request));
            return;
        }
        return;
    }

    // Livewire endpoints — network only (covers /livewire/* and /livewire-<hash>/*)
    // POST Livewire requests are already excluded by the non-GET check below,
    // but we also exclude GET requests to Livewire internals (preview-file, etc.)
    // to prevent caching dynamic or session-bound responses.
    if (/^\/livewire(-[a-z0-9]+)?\//.test(url.pathname)) return;

    // Requests with Livewire headers — network only
    if (
        request.headers.has('X-Livewire') ||
        request.headers.has('X-Livewire-Navigate') ||
        request.headers.has('X-Livewire-Stream')
    ) return;

    // Non-GET requests — network only (POST, PUT, PATCH, DELETE)
    if (request.method !== 'GET') return;

    // Static hashed assets → cache-first
    if (HASHED_ASSET_RE.test(url.pathname) || url.pathname.startsWith('/icons/')) {
        event.respondWith(cacheFirst(request));
        return;
    }

    // Other static extensions (manifest, favicon, non-hashed assets) → cache-first
    if (STATIC_EXT_RE.test(url.pathname)) {
        event.respondWith(cacheFirst(request));
        return;
    }

    // HTML / page requests → network-first with 3 s timeout
    if (request.headers.get('accept')?.includes('text/html')) {
        event.respondWith(networkFirst(request, 3000));
        return;
    }

    // Everything else — network with cache fallback
    event.respondWith(networkFirst(request, 3000));
});

// ── Strategies ───────────────────────────────────────────────────────────────

async function cacheFirst(request) {
    const cached = await caches.match(request);
    if (cached) return cached;

    try {
        const response = await fetch(request);
        if (response.ok) {
            const cache = await caches.open(CACHE_NAME);
            cache.put(request, response.clone());
        }
        return response;
    } catch (err) {
        console.warn('[SW] cache-first fetch failed:', request.url, err);
        return new Response('Offline', { status: 503, statusText: 'Service Unavailable' });
    }
}

async function networkFirst(request, timeoutMs) {
    const timeoutPromise = new Promise((_, reject) =>
        setTimeout(() => reject(new Error('Network timeout')), timeoutMs)
    );

    try {
        const response = await Promise.race([fetch(request), timeoutPromise]);
        if (response.ok) {
            const cache = await caches.open(CACHE_NAME);
            cache.put(request, response.clone());
        }
        return response;
    } catch (err) {
        const cached = await caches.match(request);
        if (cached) {
            console.log('[SW] Serving from cache (network failed):', request.url);
            return cached;
        }

        // For HTML requests, try serving the offline page
        if (request.headers.get('accept')?.includes('text/html')) {
            const offlinePage = await caches.match('/offline.html');
            if (offlinePage) return offlinePage;
        }

        return new Response('Offline', { status: 503, statusText: 'Service Unavailable' });
    }
}

/**
 * Stale-while-revalidate: serve cached response immediately if available,
 * then fetch a fresh copy in the background to update the cache.
 * Falls back to network-only when no cache entry exists.
 */
async function staleWhileRevalidate(request) {
    const cache = await caches.open(CACHE_NAME);
    const cached = await cache.match(request);

    // Fetch fresh copy in background to update cache
    const fetchPromise = fetch(request).then((response) => {
        if (response.ok) {
            cache.put(request, response.clone());
        }
        return response;
    }).catch(() => {
        // Network failed — that's fine, we may have a cached version
    });

    // Return cached immediately if available, otherwise wait for network
    if (cached) {
        return cached;
    }

    return fetchPromise.then((response) => {
        if (response) return response;
        return new Response('Offline', { status: 503, statusText: 'Service Unavailable' });
    });
}

// ── Push ────────────────────────────────────────────────────────────────────
self.addEventListener('push', (event) => {
    let data = {};
    try {
        data = event.data ? event.data.json() : {};
    } catch (err) {
        console.warn('[SW] Failed to parse push data:', err);
    }

    const title = data.title || 'Roundup Games';
    const options = {
        body: data.body || '',
        icon: data.icon || '/icons/pwa-192x192.png',
        badge: data.badge || data.icon || '/icons/pwa-192x192.png',
        data: { url: data.url || '/' },
    };

    // Tag only when the payload carries one (the PushPayload contract makes
    // tag optional): untagged notifications stack independently — a forced
    // shared tag would silently REPLACE every prior untagged notification
    // on tag-coalescing clients (Chrome/Android).
    if (typeof data.tag === 'string' && data.tag !== '') {
        options.tag = data.tag;
    }

    // M081/S05 wire contract (D286): action buttons ride as {label, url}
    // pairs — NotificationAction needs {action, title}, so the URLs travel
    // in data.actionUrls for notificationclick to resolve. Malformed
    // entries are filtered so one bad element cannot drop the whole
    // notification (an exception here would skip showNotification entirely).
    if (Array.isArray(data.actions) && data.actions.length > 0) {
        const actionUrls = {};
        options.actions = data.actions
            .filter((action) => action !== null && typeof action === 'object')
            .map((action, index) => {
                const name = `action-${index}`;
                actionUrls[name] = action.url || data.url || '/';
                return { action: name, title: action.label || 'Open' };
            });
        if (options.actions.length > 0) {
            options.data.actionUrls = actionUrls;
        } else {
            delete options.actions;
        }
    }

    // Event timestamp (epoch seconds → ms) — supporting clients render the
    // "sent at" time from Notification.timestamp.
    if (typeof data.timestamp === 'number') {
        options.timestamp = data.timestamp * 1000;
    }

    // Deadline-critical renotify (D286): a tagged replacement re-alerts
    // instead of silently updating. Only honored when a tag survived
    // above — the Notification spec throws on renotify without tag (the
    // PushPayload constructor already enforces this server-side; this is
    // the SW-side half of the contract).
    if (data.renotify === true && typeof options.tag === 'string' && options.tag !== '') {
        options.renotify = true;
    }

    console.log('[SW] Push notification:', title, options.body);
    // showNotification can reject (canonically: permission revoked while the
    // subscription still exists). Swallow it AFTER the broadcast is kept
    // alive: open tabs still learn the push arrived (live bell refresh), and
    // the rejection never surfaces as an unhandled push-handler error.
    event.waitUntil(
        Promise.resolve(self.registration.showNotification(title, options))
            .catch((err) => console.warn('[SW] showNotification failed:', err))
            .then(() => clients.matchAll({ type: 'window', includeUncontrolled: true }))
            .then((openClients) => {
                // Tell open tabs what arrived (e.g. live bell-count refresh,
                // D288 badge truth) — the page decides what to do with it.
                openClients.forEach((client) => {
                    client.postMessage({ type: 'push-received', payload: data });
                });
            })
    );
});

// ── Notification Click ──────────────────────────────────────────────────────
self.addEventListener('notificationclick', (event) => {
    event.notification.close();

    const actionUrls = event.notification.data?.actionUrls || {};
    const rawUrl = (event.action && actionUrls[event.action])
        || event.notification.data?.url
        || '/';

    // Same-origin guard: resolve the target against the SW origin and
    // refuse off-origin destinations (fallback to home) — the payload URL
    // is server-controlled today, but the SW itself must never become an
    // open-redirect seam. Payload URLs are ABSOLUTE (route() output), so
    // resolution also normalizes them for the focus matching below.
    let target;
    try {
        target = new URL(rawUrl, self.location.origin);
    } catch (e) {
        target = new URL('/', self.location.origin);
    }
    if (target.origin !== self.location.origin) {
        target = new URL('/', self.location.origin);
    }

    event.waitUntil(
        clients.matchAll({ type: 'window', includeUncontrolled: true })
            .then((windowClients) => {
                // Push action URLs are signed read-marker URLs (D288): landing
                // on the marker marks the sibling in-app notification read and
                // 302s to the real target. So an open window can NEVER be
                // showing the marker pathname — match instead on the decoded
                // redirect target's path, and navigate the matched window
                // THROUGH the marker so the read-marking still happens.
                const redirect = target.searchParams.get('redirect');
                let matchPath = target.pathname;
                if (redirect) {
                    try {
                        const redirectUrl = new URL(redirect, self.location.origin);
                        if (redirectUrl.origin === self.location.origin) {
                            matchPath = redirectUrl.pathname;
                        }
                    } catch (e) {
                        // Malformed redirect — fall back to the marker path.
                    }
                }

                // Focus an existing window already showing the target path —
                // compare pathnames (query strings differ per visit and the
                // payload URL string is absolute, so a raw string comparison
                // would never match).
                for (const client of windowClients) {
                    try {
                        if (new URL(client.url).pathname === matchPath && 'focus' in client) {
                            // Navigate through the marker URL when the payload
                            // target differs from what the window shows, so the
                            // cross-channel read-marking runs even on focus.
                            // navigate() rejects for clients this SW does not
                            // control (includeUncontrolled matching) — focus anyway.
                            if ('navigate' in client && target.href !== client.url) {
                                return client.navigate(target.href)
                                    .then(() => client.focus())
                                    .catch(() => client.focus());
                            }
                            return client.focus();
                        }
                    } catch (e) {
                        // Invalid client URL, skip
                    }
                }
                // No matching window — open a new one
                return clients.openWindow(target.href);
            })
    );
});

// ── Message handler ─────────────────────────────────────────────────────────
self.addEventListener('message', (event) => {
    if (event.data?.type === 'SKIP_WAITING') {
        console.log('[SW] Received SKIP_WAITING — activating new worker');
        self.skipWaiting();
        return;
    }
});

// ── Subscription rotation (pushsubscriptionchange) ──────────────────────
// urlBase64ToUint8Array lives in resources/js/push.js (page context); the
// worker needs its own copy to resubscribe with the VAPID key.
function urlBase64ToUint8Array(base64String) {
    const padding = '='.repeat((4 - (base64String.length % 4)) % 4);
    const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
    const raw = atob(base64);
    const output = new Uint8Array(raw.length);
    for (let i = 0; i < raw.length; i += 1) {
        output[i] = raw.charCodeAt(i);
    }
    return output;
}

// Chrome and Firefox rotate push subscriptions periodically: the endpoint
// and keys are replaced under the user. Without handling, the server row
// 404/410s on the next send and gets pruned, while the browser still holds
// the NEW subscription — the user is silently unreachable by push forever
// (the settings UI keeps saying "subscribed" from the local subscription).
// The subscribe endpoint upserts on (endpoint, user), so re-POSTing the new
// subscription is a safe idempotent heal; the old endpoint is unsubscribed
// server-side when PushChannel next prunes it (or the browser unsubscribes
// it below, whose server call is best-effort).
self.addEventListener('pushsubscriptionchange', (event) => {
    const subscribe = async () => {
        try {
            const registration = await self.registration;
            const oldSubscription = event.oldSubscription
                || await registration.pushManager.getSubscription();

            if (oldSubscription) {
                // Best-effort: drop the server row for the rotated-out endpoint.
                try {
                    await fetch('/api/v1/push/subscribe', {
                        method: 'DELETE',
                        credentials: 'same-origin',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ endpoint: oldSubscription.endpoint }),
                    });
                    await oldSubscription.unsubscribe();
                } catch (err) {
                    console.warn('[SW] Old-subscription cleanup failed:', err);
                }
            }

            // Fetch the VAPID key and resubscribe — the fresh subscription
            // lands on the server through the same validated upsert the
            // subscribe button uses.
            const keyResp = await fetch('/api/v1/push/vapid-public-key', {
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
            });
            if (!keyResp.ok) {
                console.warn('[SW] VAPID key endpoint returned', keyResp.status);
                return;
            }
            const { public_key: vapidKey } = await keyResp.json();
            if (!vapidKey) return;

            const applicationServerKey = urlBase64ToUint8Array(vapidKey);
            const subscription = await registration.pushManager.subscribe({
                userVisibleOnly: true,
                applicationServerKey,
            });

            const payload = subscription.toJSON();
            await fetch('/api/v1/push/subscribe', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                },
                body: JSON.stringify({
                    endpoint: payload.endpoint,
                    keys: {
                        p256h: payload.keys?.p256dh || '',
                        auth: payload.keys?.auth || '',
                    },
                }),
            });
        } catch (err) {
            console.warn('[SW] pushsubscriptionchange resubscribe failed:', err);
        }
    };

    event.waitUntil(subscribe());
});
