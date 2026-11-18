import { describe, expect, it, vi, beforeEach } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

/**
 * Render-layer regression for the service-worker push handler (M081/S05,
 * D286): the wire payload's richness (action buttons, event timestamp,
 * monochrome badge, renotify) must survive into showNotification() — the
 * original handler silently dropped actions/timestamp/renotify, so the
 * Confirm-spot button could never render on any client. The handler is
 * executed against a mock ServiceWorkerGlobalScope; no browser needed.
 */

function loadServiceWorker({ windows = [], showNotificationError = null } = {}) {
    const listeners = {};
    const shown = [];
    const opened = [];

    const registration = {
        showNotification: vi.fn(async (title, options) => {
            if (showNotificationError) {
                throw showNotificationError;
            }
            shown.push({ title, options });
        }),
    };

    const clients = {
        matchAll: vi.fn(async () => windows),
        openWindow: vi.fn(async (url) => {
            opened.push(url);
        }),
    };

    const self = {
        addEventListener: (type, handler) => {
            listeners[type] = handler;
        },
        skipWaiting: vi.fn(),
        // A real service worker always has self.location (the SW's own
        // scope URL) — the notificationclick origin guard reads it.
        location: new URL('https://sw.test/'),
        clients,
        registration,
    };

    const code = readFileSync(resolve(process.cwd(), 'public/sw.js'), 'utf8');
    // Evaluate the worker script with `self` as the global-ish receiver.
    const run = new Function('self', 'clients', code);
    run(self, clients);

    return { listeners, shown, opened, clients, registration };
}

function pushEvent(payload) {
    let completion;
    const event = {
        data: { json: () => payload },
        waitUntil: (promise) => {
            completion = promise;
        },
    };
    event.done = () => completion ?? Promise.resolve();

    return event;
}

describe('sw.js push render layer (D286)', () => {
    let sw;

    beforeEach(() => {
        sw = loadServiceWorker();
    });

    it('renders title, body, tag and target url from the payload', async () => {
        await sw.listeners.push(pushEvent({
            title: 'A spot opened!',
            body: 'Confirm your seat before Friday',
            url: '/en/dashboard/games/probe',
            tag: 'waitlist-promoted-game-probe',
        }));
        await Promise.resolve();

        expect(sw.shown).toHaveLength(1);
        const { title, options } = sw.shown[0];
        expect(title).toBe('A spot opened!');
        expect(options.body).toBe('Confirm your seat before Friday');
        expect(options.tag).toBe('waitlist-promoted-game-probe');
        expect(options.data.url).toBe('/en/dashboard/games/probe');
    });

    it('maps action buttons to NotificationActions and routes their URLs through data.actionUrls', async () => {
        await sw.listeners.push(pushEvent({
            title: 't',
            body: 'b',
            url: '/base',
            actions: [
                { label: 'Confirm spot', url: '/en/games/probe/confirm' },
            ],
        }));
        await Promise.resolve();

        const { options } = sw.shown[0];
        expect(options.actions).toEqual([{ action: 'action-0', title: 'Confirm spot' }]);
        expect(options.data.actionUrls).toEqual({ 'action-0': '/en/games/probe/confirm' });
    });

    it('converts the epoch-seconds event timestamp to milliseconds for Notification.timestamp', async () => {
        await sw.listeners.push(pushEvent({ title: 't', body: 'b', timestamp: 1766000000 }));
        await Promise.resolve();

        expect(sw.shown[0].options.timestamp).toBe(1766000000 * 1000);
    });

    it('honours the monochrome badge path and renotify on deadline-critical pushes', async () => {
        await sw.listeners.push(pushEvent({
            title: 't',
            body: 'b',
            badge: '/icons/push-badge.png',
            renotify: true,
            tag: 'stable-tag',
        }));
        await Promise.resolve();

        const { options } = sw.shown[0];
        expect(options.badge).toBe('/icons/push-badge.png');
        expect(options.renotify).toBe(true);
    });

    it('opens the action URL when a notification action button is clicked', async () => {
        await sw.listeners.push(pushEvent({
            title: 't',
            body: 'b',
            url: '/base',
            actions: [{ label: 'Confirm spot', url: '/en/games/probe/confirm' }],
        }));
        await Promise.resolve();

        const notification = { close: vi.fn(), data: sw.shown[0].options.data };
        await sw.listeners.notificationclick({ action: 'action-0', notification, waitUntil: () => Promise.resolve() });
        await Promise.resolve();

        // Relative targets are resolved against the SW origin (same-origin
        // normalization) — openWindow receives an absolute app URL.
        expect(sw.opened).toEqual(['https://sw.test/en/games/probe/confirm']);
    });

    it('refuses off-origin click targets and falls back to home', async () => {
        await sw.listeners.push(pushEvent({ title: 't', body: 'b', url: 'https://evil.test/phish' }));
        await Promise.resolve();

        const notification = { close: vi.fn(), data: sw.shown[0].options.data };
        await sw.listeners.notificationclick({ action: '', notification, waitUntil: () => Promise.resolve() });
        await Promise.resolve();

        expect(sw.opened).toEqual(['https://sw.test/']);
    });

    it('leaves untagged notifications without a forced shared tag', async () => {
        await sw.listeners.push(pushEvent({ title: 't', body: 'b', url: '/x' }));
        await Promise.resolve();

        expect(sw.shown[0].options.tag).toBeUndefined();
    });

    it('falls back to the payload url on body click, and to the icon when no badge ships', async () => {
        await sw.listeners.push(pushEvent({ title: 't', body: 'b', url: '/base', icon: '/icons/pwa-192x192.png' }));
        await Promise.resolve();

        expect(sw.shown[0].options.badge).toBe('/icons/pwa-192x192.png');

        const notification = { close: vi.fn(), data: sw.shown[0].options.data };
        await sw.listeners.notificationclick({ action: '', notification, waitUntil: () => Promise.resolve() });
        await Promise.resolve();

        expect(sw.opened).toEqual(['https://sw.test/base']);
    });

    it('filters malformed action entries instead of dropping the whole notification', async () => {
        await sw.listeners.push(pushEvent({
            title: 't',
            body: 'b',
            url: '/base',
            actions: [null, 'garbage-string', { label: 'Confirm spot' }],
        }));
        await Promise.resolve();

        // The one well-formed entry survives (with the payload-url fallback);
        // null/string entries are dropped without throwing — an exception in
        // the push handler would skip showNotification entirely.
        expect(sw.shown).toHaveLength(1);
        expect(sw.shown[0].options.actions).toEqual([{ action: 'action-0', title: 'Confirm spot' }]);
        expect(sw.shown[0].options.data.actionUrls).toEqual({ 'action-0': '/base' });
    });

    it('suppresses renotify when the payload carries no tag (spec: renotify requires one)', async () => {
        await sw.listeners.push(pushEvent({ title: 't', body: 'b', url: '/x', renotify: true }));
        await Promise.resolve();

        expect(sw.shown[0].options.renotify).toBeUndefined();
    });

    it('broadcasts push-received to open windows (the D288 live-refresh channel)', async () => {
        const client = { url: 'https://sw.test/en/dashboard', postMessage: vi.fn() };
        const local = loadServiceWorker({ windows: [client] });

        const event = pushEvent({ title: 't', body: 'b', url: '/x', tag: 'tag-1' });
        await local.listeners.push(event);
        await event.done();

        expect(client.postMessage).toHaveBeenCalledWith({
            type: 'push-received',
            payload: expect.objectContaining({ title: 't', tag: 'tag-1' }),
        });
    });

    it('still broadcasts push-received when showNotification rejects (permission revoked mid-subscription)', async () => {
        // The chain previously had no catch: a NotAllowedError rejection
        // skipped the postMessage broadcast (open tabs never refreshed the
        // bell) and settled the waitUntil promise rejected, surfacing as a
        // push-handler error (M081 fifth-pass review).
        const client = { url: 'https://sw.test/en/dashboard', postMessage: vi.fn() };
        const local = loadServiceWorker({
            windows: [client],
            showNotificationError: new DOMException('Permission denied', 'NotAllowedError'),
        });

        const event = pushEvent({ title: 't', body: 'b', url: '/x' });
        await local.listeners.push(event);

        // The waitUntil promise must settle fulfilled, not rejected.
        await expect(event.done()).resolves.toBeUndefined();
        expect(client.postMessage).toHaveBeenCalledWith({
            type: 'push-received',
            payload: expect.objectContaining({ title: 't' }),
        });
        expect(local.shown).toHaveLength(0);
    });

    it('focuses a window already showing the marker redirect target and navigates it through the marker', async () => {
        // Push action URLs are signed read-marker URLs: landing marks the
        // sibling in-app notification read and 302s to the redirect target.
        // A window can never BE on the marker path — matching must use the
        // decoded redirect param, and the focused window navigates THROUGH
        // the marker so the read-marking still runs.
        const markerUrl = 'https://sw.test/en/notifications/read-marker/42?redirect=%2Fen%2Fgames%2Fprobe&type=X&signature=abc';
        const client = {
            url: 'https://sw.test/en/games/probe?filter=mine',
            focus: vi.fn(async () => 'focused'),
            navigate: vi.fn(async () => ({})),
            postMessage: vi.fn(),
        };
        const local = loadServiceWorker({ windows: [client] });
        await local.listeners.push(pushEvent({ title: 't', body: 'b', url: markerUrl, tag: 'tag-1' }));
        await Promise.resolve();

        const notification = { close: vi.fn(), data: local.shown[0].options.data };
        await local.listeners.notificationclick({ action: '', notification, waitUntil: () => Promise.resolve() });
        await Promise.resolve();
        await Promise.resolve();

        expect(client.navigate).toHaveBeenCalledWith(markerUrl);
        expect(client.focus).toHaveBeenCalled();
        expect(local.opened).toEqual([]);
    });

    it('still opens a new window when no window matches the marker redirect target', async () => {
        const markerUrl = 'https://sw.test/en/notifications/read-marker/42?redirect=%2Fen%2Fgames%2Fprobe&type=X&signature=abc';
        const unrelated = {
            url: 'https://sw.test/en/teams',
            focus: vi.fn(async () => 'focused'),
            navigate: vi.fn(async () => ({})),
            postMessage: vi.fn(),
        };
        const local = loadServiceWorker({ windows: [unrelated] });
        await local.listeners.push(pushEvent({ title: 't', body: 'b', url: markerUrl, tag: 'tag-1' }));
        await Promise.resolve();

        const notification = { close: vi.fn(), data: local.shown[0].options.data };
        await local.listeners.notificationclick({ action: '', notification, waitUntil: () => Promise.resolve() });
        await Promise.resolve();
        await Promise.resolve();

        expect(unrelated.navigate).not.toHaveBeenCalled();
        expect(local.opened).toEqual([markerUrl]);
    });
});

describe('sw.js pushsubscriptionchange rotation heal', () => {
    /**
     * Endpoint rotation self-heal (M081 review): the browser replaces the
     * subscription under the user; without a handler the server row gets
     * pruned on the next 404/410 and the user is silently unreachable.
     * The handler must DELETE the old endpoint server-side, resubscribe
     * with the VAPID key, and POST the fresh subscription through the same
     * validated upsert the subscribe button uses.
     */
    function rotationWorker({ subscribe } = {}) {
        const listeners = {};
        const calls = { deletes: [], posts: [] };
        const oldSubscription = {
            endpoint: 'https://push.example.test/old',
            unsubscribe: vi.fn(async () => true),
        };

        const registration = {
            pushManager: {
                getSubscription: vi.fn(async () => oldSubscription),
                subscribe: vi.fn(async () => ({
                    toJSON: () => ({
                        endpoint: 'https://push.example.test/new',
                        keys: { p256dh: 'NEWP256DH', auth: 'NEWAUTH' },
                    }),
                })),
            },
        };

        const fetchMock = vi.fn(async (url, init = {}) => {
            if (String(url).includes('/push/vapid-public-key')) {
                return { ok: true, json: async () => ({ public_key: 'BEl62iUYgUIstl2wk7V2YYjZV9pSxY'.replace(/[^A-Za-z0-9_-]/g, 'A') }) };
            }
            if (init.method === 'DELETE') {
                calls.deletes.push(JSON.parse(init.body));
                return { ok: true };
            }
            if (init.method === 'POST') {
                calls.posts.push(JSON.parse(init.body));
                return { ok: true };
            }
            return { ok: true, json: async () => ({}) };
        });

        const self = {
            addEventListener: (type, handler) => { listeners[type] = handler; },
            skipWaiting: vi.fn(),
            location: new URL('https://sw.test/'),
            clients: { matchAll: vi.fn(async () => []), openWindow: vi.fn(async () => {}) },
            registration,
        };

        const code = readFileSync(resolve(process.cwd(), 'public/sw.js'), 'utf8');
        // atob is available in Node 16+; bind it into the evaluation scope.
        const run = new Function('self', 'clients', 'atob', 'fetch', code);
        run(self, self.clients, atob, fetchMock);

        return { listeners, calls, fetchMock, registration, oldSubscription };
    }

    it('resubscribes and re-registers the rotated subscription, clearing the old endpoint', async () => {
        const { listeners, calls, oldSubscription } = rotationWorker();

        let completion;
        const event = {
            oldSubscription,
            waitUntil: (promise) => { completion = promise; },
        };

        await listeners.pushsubscriptionchange(event);
        await completion;

        expect(calls.deletes).toEqual([{ endpoint: 'https://push.example.test/old' }]);
        expect(oldSubscription.unsubscribe).toHaveBeenCalled();
        expect(calls.posts).toHaveLength(1);
        expect(calls.posts[0].endpoint).toBe('https://push.example.test/new');
        expect(calls.posts[0].keys).toEqual({ p256h: 'NEWP256DH', auth: 'NEWAUTH' });
    });

    it('never throws out of the handler when the endpoints fail', async () => {
        const failing = rotationWorker();
        failing.fetchMock.mockImplementation(async () => ({ ok: false, json: async () => ({}) }));

        let completion;
        const event = {
            oldSubscription: failing.oldSubscription,
            waitUntil: (promise) => { completion = promise; },
        };

        await expect(listenersSafeRun(failing, event)).resolves.toBeUndefined();
        await completion;
        expect(failing.calls.posts).toHaveLength(0);
    });

    async function listenersSafeRun(harness, event) {
        await harness.listeners.pushsubscriptionchange(event);
    }
});
