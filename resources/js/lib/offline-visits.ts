import { router } from '@inertiajs/react';
import { hasOfflineFallback } from '@/sw-routes';

type Visit = { method: string; url: URL; prefetch: boolean };

/**
 * Where a visit that failed for want of network loads in full (CHW-35): a
 * page of My cards, under a service worker, which answers that page load
 * with the cards kept on the phone. Nothing for any other visit.
 */
export function fullLoadWhenOffline(
    visit: Visit | null,
    controlled: boolean,
    origin: string,
): string | null {
    if (
        !visit ||
        !controlled ||
        visit.prefetch ||
        visit.method.toLowerCase() !== 'get' ||
        !hasOfflineFallback(visit.url, origin)
    ) {
        return null;
    }

    return visit.url.href;
}

/**
 * Offline, a tap on My cards in the app (an Inertia visit, which the
 * service worker never answers with a page) becomes a page load the
 * service worker does answer. Returns the way to stop.
 */
export function loadInFullWhenOffline(): () => void {
    let current: Visit | null = null;
    const stops = [
        router.on('start', (event) => {
            if (!event.detail.visit.prefetch) {
                current = event.detail.visit;
            }
        }),
        router.on('finish', (event) => {
            if (event.detail.visit === current) {
                current = null;
            }
        }),
        router.on('networkError', () => {
            const url = fullLoadWhenOffline(
                current,
                Boolean(navigator.serviceWorker?.controller),
                window.location.origin,
            );

            if (!url) {
                return;
            }

            window.location.assign(url);

            // Handled: no error for a page that is about to load in full.
            return false;
        }),
    ];

    return () => stops.forEach((stop) => stop());
}
