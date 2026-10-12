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
    NETWORK_ROUTES,
    networkOrOffline,
    routeFor,
} from './sw-routes';
import type { StaticRoute, SwRoute } from './sw-routes';

declare const self: ServiceWorkerGlobalScope;

/**
 * punchcard's service worker (CHW-35), for the customer app and the business
 * app alike: the app shell is precached, fonts are kept once used, logos
 * and banners are refreshed in the background, and nothing else is ever
 * cached (routeFor). Page loads and writes skip it entirely where the
 * browser can (NETWORK_ROUTES). A new version waits until the page asks it
 * to take over (the update toast).
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

// My cards' pages (CHW-35): from the network, else (offline, or no answer in 8 s) the offline page, which shows the
// cards kept on the phone.
registerRoute(
    ({ request, url }) =>
        request.mode === 'navigate' &&
        hasOfflineFallback(url, self.location.origin),
    ({ request }) =>
        networkOrOffline(
            () => fetch(request),
            () => matchPrecache('/offline'),
        ),
);

self.addEventListener('message', (event) => {
    if (event.data?.type === 'SKIP_WAITING') {
        void self.skipWaiting();
    }
});
