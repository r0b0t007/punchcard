/// <reference lib="webworker" />
import { ExpirationPlugin } from 'workbox-expiration';
import {
    cleanupOutdatedCaches,
    matchPrecache,
    precacheAndRoute,
} from 'workbox-precaching';
import { registerRoute } from 'workbox-routing';
import { CacheFirst, StaleWhileRevalidate } from 'workbox-strategies';
import type { Strategy } from 'workbox-strategies';
import {
    hasOfflineFallback,
    isMyCardsVisit,
    loadInFull,
    NETWORK_ROUTES,
    networkOrOffline,
    preloadedOrFetched,
    routeFor,
} from './sw-routes';
import type { StaticRoute, SwRoute } from './sw-routes';
import { notificationFrom, pageToOpen } from './sw-push';

declare const self: ServiceWorkerGlobalScope;

/**
 * punchcard's service worker (CHW-35), for the customer app and the business
 * app alike: the app shell is precached, fonts are kept once used, logos
 * and banners are refreshed in the background, and nothing else is ever
 * cached (routeFor). Page loads and writes skip it entirely where the
 * browser can (NETWORK_ROUTES); the page loads that reach it start while
 * it boots (navigation preload). A new version waits until the page asks
 * it to take over (the update toast).
 */
self.addEventListener('install', (event) => {
    const install = event as ExtendableEvent & {
        addRoutes?: (routes: StaticRoute[]) => Promise<void>;
    };

    if (install.addRoutes) {
        // A shortcut only: a browser that refuses a rule still installs the worker.
        install.waitUntil(
            install.addRoutes(NETWORK_ROUTES).catch(() => undefined),
        );
    }
});

// Where the browser has it (Safari 15.4+, Chromium): a page load doesn't wait for the worker to boot.
const preloads = 'navigationPreload' in self.registration;

self.addEventListener('activate', (event) => {
    if (preloads) {
        event.waitUntil(self.registration.navigationPreload.enable());
    }
});

precacheAndRoute(self.__WB_MANIFEST);
cleanupOutdatedCaches();

// Only a 200 is ever kept (Workbox's default).
const strategies: Record<NonNullable<SwRoute>, Strategy> = {
    fonts: new CacheFirst({
        cacheName: 'fonts',
        plugins: [new ExpirationPlugin({ maxEntries: 30 })],
    }),
    images: new StaleWhileRevalidate({
        cacheName: 'images',
        plugins: [
            new ExpirationPlugin({
                maxEntries: 60,
                maxAgeSeconds: 30 * 24 * 60 * 60,
            }),
        ],
    }),
};

// routeFor once per request: Workbox hands the match to the handler as params.
registerRoute(
    ({ request, url }) => {
        const route = routeFor(request.method, url, self.location.origin);

        return route ? { route } : false;
    },
    ({ event, request, params }) =>
        strategies[(params as { route: NonNullable<SwRoute> }).route].handle({
            event,
            request,
        }),
);

// GET page loads, from their preload (once started, it must be the answer: anything else would load the page twice);
// a form posted as a page load is left to the browser. My cards' pages (CHW-35) fall back to the offline page, which
// shows the cards kept on the phone; without preload, other pages are left to the browser too.
registerRoute(
    ({ request, url }) => {
        if (request.method !== 'GET' || request.mode !== 'navigate') {
            return false;
        }

        const myCards = hasOfflineFallback(url, self.location.origin);

        return myCards || preloads ? { myCards } : false;
    },
    ({ event, request, params }) => {
        const fromNetwork = () =>
            preloadedOrFetched((event as FetchEvent).preloadResponse, () =>
                fetch(request),
            );

        return (params as { myCards: boolean }).myCards
            ? networkOrOffline(fromNetwork, () => matchPrecache('/offline'))
            : fromNetwork();
    },
);

// A tap on My cards in the app (an Inertia visit) that can't reach the network: loaded in full instead, as a page
// load the route above answers with the offline page. A worker without that page never asks for it.
registerRoute(
    ({ request, url }) =>
        isMyCardsVisit(
            request.method,
            url,
            request.headers,
            self.location.origin,
        ),
    ({ request, url }) => fetch(request).catch(() => loadInFull(url)),
);

self.addEventListener('message', (event) => {
    if (event.data?.type === 'SKIP_WAITING') {
        void self.skipWaiting();
    }
});

// A notification (CHW-41): shown as the server sent it (sw-push.ts).
self.addEventListener('push', (event) => {
    let payload: unknown = null;

    try {
        payload = event.data?.json();
    } catch {
        // Not JSON: punchcard's name alone, leading home.
    }

    const { title, options } = notificationFrom(payload);

    event.waitUntil(self.registration.showNotification(title, options));
});

// A tap on it opens its page in the app's window if one is open, else a new one; never another site.
self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    const url = pageToOpen(event.notification.data, self.location.origin);

    event.waitUntil(
        (async () => {
            const windows = await self.clients.matchAll({
                type: 'window',
                includeUncontrolled: true,
            });
            const open = windows.find(
                (client) => new URL(client.url).origin === self.location.origin,
            );

            if (open) {
                await open.focus();
                await open
                    .navigate(url)
                    .catch(() => self.clients.openWindow(url));

                return;
            }

            await self.clients.openWindow(url);
        })(),
    );
});
