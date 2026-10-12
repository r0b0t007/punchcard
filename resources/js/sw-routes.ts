/**
 * What the service worker does with a request (CHW-35, pwa-conventions
 * skill). Kept apart from sw.ts so it can be unit-tested.
 *
 * - The customer's cards (`/api/me/cards`): network first, the last copy
 *   when offline.
 * - Logos and banners: stale while revalidate.
 * - Everything else goes to the network, untouched: the tap endpoint `/t`,
 *   sign-in and every other page, every POST/PUT/PATCH/DELETE and all
 *   business data. Built assets and fonts come from the precache.
 */
export type SwRoute = 'my-cards' | 'images' | null;

export function routeFor(method: string, url: URL, origin: string): SwRoute {
    if (method !== 'GET' || url.origin !== origin) {
        return null;
    }

    if (url.pathname === '/api/me/cards') {
        return 'my-cards';
    }

    if (/^\/storage\/(logos|banners)\/[^/]+\/[^/]+$/.test(url.pathname)) {
        return 'images';
    }

    return null;
}
