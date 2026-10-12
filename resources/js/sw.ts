/// <reference lib="webworker" />
import { ExpirationPlugin } from 'workbox-expiration';
import { cleanupOutdatedCaches, precacheAndRoute } from 'workbox-precaching';
import { registerRoute } from 'workbox-routing';
import { NetworkFirst, StaleWhileRevalidate } from 'workbox-strategies';
import { routeFor } from './sw-routes';

declare const self: ServiceWorkerGlobalScope;

/**
 * punchcard's service worker (CHW-35), for the customer app and the business
 * app alike: the built assets and fonts are precached, the customer's cards
 * are kept for offline use, logos and banners are refreshed in the
 * background, and nothing else is ever cached (routeFor). A new version
 * waits until the page asks it to take over (the update toast).
 */
precacheAndRoute(self.__WB_MANIFEST);
cleanupOutdatedCaches();

registerRoute(
    ({ request, url }) =>
        routeFor(request.method, url, self.location.origin) === 'my-cards',
    new NetworkFirst({ cacheName: 'me-cards', networkTimeoutSeconds: 5 }),
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
