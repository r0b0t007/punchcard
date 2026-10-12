/// <reference lib="webworker" />
import { ExpirationPlugin } from 'workbox-expiration';
import { cleanupOutdatedCaches, precacheAndRoute } from 'workbox-precaching';
import { registerRoute } from 'workbox-routing';
import { CacheFirst, StaleWhileRevalidate } from 'workbox-strategies';
import { NETWORK_ROUTES, routeFor } from './sw-routes';
import type { StaticRoute } from './sw-routes';

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
        install.waitUntil(install.addRoutes(NETWORK_ROUTES));
    }
});

precacheAndRoute(self.__WB_MANIFEST);
cleanupOutdatedCaches();

registerRoute(
    ({ request, url }) =>
        routeFor(request.method, url, self.location.origin) === 'fonts',
    new CacheFirst({
        cacheName: 'fonts',
        // Only a 200 is kept (Workbox's default).
        plugins: [new ExpirationPlugin({ maxEntries: 30 })],
    }),
);

registerRoute(
    ({ request, url }) =>
        routeFor(request.method, url, self.location.origin) === 'images',
    new StaleWhileRevalidate({
        cacheName: 'images',
        plugins: [
            new ExpirationPlugin({
                maxEntries: 60,
                maxAgeSeconds: 30 * 24 * 60 * 60,
            }),
        ],
    }),
);

self.addEventListener('message', (event) => {
    if (event.data?.type === 'SKIP_WAITING') {
        void self.skipWaiting();
    }
});
