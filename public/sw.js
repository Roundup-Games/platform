/**
 * RoundupGames Service Worker
 *
 * Caching strategies:
 *  - Static assets (hashed JS/CSS, icons, manifest): cache-first
 *  - HTML/page requests: network-first (3 s timeout)
 *  - Public API (/api/geocode): stale-while-revalidate
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
    if (url.pathname.startsWith('/api/')) {
        // Exception: geocode and session-count are unauthenticated public endpoints.
        // Stale-while-revalidate: serve cached response immediately, update cache in background.
        if (url.pathname === '/api/geocode') {
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
                    try {
                        const clientPath = new URL(client.url).pathname;
                        if ((clientPath === url || clientPath.startsWith(url + '?')) && 'focus' in client) {
                            return client.focus();
                        }
                    } catch (e) {
                        // Invalid URL, skip
                    }
                }
                // No matching window — open a new one
                return clients.openWindow(url);
            })
    );
});

// ── Background Sync: Offline Action Queue ──────────────────────────────────
// Queues form submissions and actions made while offline, then replays them
// when connectivity is restored via the Background Sync API.
// Falls back to a "reconnect to complete" toast when Background Sync is unavailable.

const OFFLINE_DB = 'roundup-offline-queue';
const OFFLINE_STORE = 'actions';
const OFFLINE_DB_VERSION = 1;

function openQueueDB() {
    return new Promise((resolve, reject) => {
        const request = indexedDB.open(OFFLINE_DB, OFFLINE_DB_VERSION);
        request.onupgradeneeded = (event) => {
            const db = event.target.result;
            if (!db.objectStoreNames.contains(OFFLINE_STORE)) {
                db.createObjectStore(OFFLINE_STORE, { keyPath: 'id', autoIncrement: true });
            }
        };
        request.onsuccess = () => resolve(request.result);
        request.onerror = () => reject(request.error);
    });
}

async function queueOfflineAction(action) {
    const db = await openQueueDB();
    const tx = db.transaction(OFFLINE_STORE, 'readwrite');
    const store = tx.objectStore(OFFLINE_STORE);

    // Enforce max queue size (50 actions). Drop oldest if full.
    const MAX_QUEUE_SIZE = 50;
    const countRequest = store.count();
    const currentCount = await new Promise((resolve, reject) => {
        countRequest.onsuccess = () => resolve(countRequest.result);
        countRequest.onerror = () => reject(countRequest.error);
    });
    if (currentCount >= MAX_QUEUE_SIZE) {
        // Delete the oldest action
        const firstKey = store.getAllKeys();
        const oldestId = await new Promise((resolve, reject) => {
            firstKey.onsuccess = () => resolve(firstKey.result[0]);
            firstKey.onerror = () => reject(firstKey.error);
        });
        if (oldestId !== undefined) {
            store.delete(oldestId);
        }
    }

    store.add({
        url: action.url,
        method: action.method || 'POST',
        body: action.body,
        headers: action.headers || {},
        timestamp: Date.now(),
    });
    return new Promise((resolve, reject) => {
        tx.oncomplete = () => resolve();
        tx.onerror = () => reject(tx.error);
    });
}

async function getQueuedActions() {
    const db = await openQueueDB();
    const tx = db.transaction(OFFLINE_STORE, 'readonly');
    const store = tx.objectStore(OFFLINE_STORE);
    const request = store.getAll();
    return new Promise((resolve, reject) => {
        request.onsuccess = () => resolve(request.result);
        request.onerror = () => reject(request.error);
    });
}

async function removeQueuedAction(id) {
    const db = await openQueueDB();
    const tx = db.transaction(OFFLINE_STORE, 'readwrite');
    const store = tx.objectStore(OFFLINE_STORE);
    store.delete(id);
    return new Promise((resolve, reject) => {
        tx.oncomplete = () => resolve();
        tx.onerror = () => reject(tx.error);
    });
}

async function clearQueue() {
    const db = await openQueueDB();
    const tx = db.transaction(OFFLINE_STORE, 'readwrite');
    const store = tx.objectStore(OFFLINE_STORE);
    store.clear();
    return new Promise((resolve, reject) => {
        tx.oncomplete = () => resolve();
        tx.onerror = () => reject(tx.error);
    });
}

// Handle sync event — replay queued actions when back online
self.addEventListener('sync', (event) => {
    if (event.tag === 'roundup-offline-actions') {
        event.waitUntil(replayQueuedActions());
    }
});

async function replayQueuedActions() {
    const actions = await getQueuedActions();
    if (actions.length === 0) return;

    console.log(`[SW] Replaying ${actions.length} offline action(s)`);

    for (const action of actions) {
        try {
            const response = await fetch(action.url, {
                method: action.method,
                headers: {
                    'Content-Type': 'application/json',
                    'X-Offline-Replay': 'true',
                    ...action.headers,
                },
                body: action.body,
                credentials: 'same-origin',
            });

            if (response.ok) {
                await removeQueuedAction(action.id);
                console.log(`[SW] Replayed action: ${action.method} ${action.url}`);
            } else if (response.status >= 400 && response.status < 500) {
                // Permanent client error — this action will never succeed.
                // Remove it and continue processing the rest of the queue.
                console.warn(`[SW] Permanent replay failure (${response.status}): ${action.method} ${action.url} — removing from queue`);
                await removeQueuedAction(action.id);
                // Continue to next action
            } else {
                // Server error (5xx) — transient, stop and retry later
                console.warn(`[SW] Server error (${response.status}): ${action.method} ${action.url} — stopping replay`);
                break;
            }
        } catch (error) {
            console.warn(`[SW] Replay error: ${error.message}`);
            break; // Network issue — stop and retry later
        }
    }
}

// ── Message handler ─────────────────────────────────────────────────────────
self.addEventListener('message', (event) => {
    if (event.data?.type === 'SKIP_WAITING') {
        console.log('[SW] Received SKIP_WAITING — activating new worker');
        self.skipWaiting();
        return;
    }

    if (event.data?.type === 'QUEUE_OFFLINE_ACTION') {
        event.waitUntil(
            queueOfflineAction(event.data.payload).then(() => {
                // Respond via the provided message port
                if (event.ports && event.ports[0]) {
                    event.ports[0].postMessage({ type: 'OFFLINE_ACTION_QUEUED' });
                }
            })
        );
    }
});
