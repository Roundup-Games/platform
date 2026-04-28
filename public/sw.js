/**
 * RoundupGames Service Worker
 *
 * Caching strategies:
 *  - Static assets (hashed JS/CSS, icons, manifest): cache-first
 *  - HTML/page requests: network-first (3 s timeout)
 *  - API requests (/api/*): network-only
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
            for (const entry of Object.values(manifest)) {
                if (entry.file) {
                    urls.push('/build/' + entry.file);
                }
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
    if (url.pathname.startsWith('/api/')) return;

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
        badge: '/icons/pwa-192x192.png',
        data: { url: data.url || '/' },
        tag: data.tag || 'default',
    };

    console.log('[SW] Push notification:', title, options.body);
    event.waitUntil(self.registration.showNotification(title, options));
});

// ── Notification Click ──────────────────────────────────────────────────────
self.addEventListener('notificationclick', (event) => {
    event.notification.close();

    const url = event.notification.data?.url || '/';
    console.log('[SW] Notification click, target URL:', url);

    event.waitUntil(
        clients.matchAll({ type: 'window', includeUncontrolled: true })
            .then((windowClients) => {
                // Focus an existing window showing the target URL
                for (const client of windowClients) {
                    if (client.url.includes(url) && 'focus' in client) {
                        return client.focus();
                    }
                }
                // No matching window — open a new one
                return clients.openWindow(url);
            })
    );
});

// ── Message handler ─────────────────────────────────────────────────────────
self.addEventListener('message', (event) => {
    if (event.data?.type === 'SKIP_WAITING') {
        console.log('[SW] Received SKIP_WAITING — activating new worker');
        self.skipWaiting();
    }
});
