/**
 * What the service worker does with a request (CHW-35, pwa-conventions
 * skill). Kept apart from sw.ts so it can be unit-tested.
 *
 * - Fonts: cache first, the first time a page uses one (their names are
 *   hashed, and a page only loads the subsets it shows).
 * - Logos and banners: stale while revalidate.
 * - Everything else goes to the network, untouched: the tap endpoint `/t`,
 *   sign-in and every other page, every POST/PUT/PATCH/DELETE and all
 *   business data. The app shell comes from the precache.
 */
export type SwRoute = 'fonts' | 'images' | null;

export function routeFor(method: string, url: URL, origin: string): SwRoute {
    if (method !== 'GET' || url.origin !== origin) {
        return null;
    }

    if (/^\/build\/assets\/[^/]+\.woff2$/.test(url.pathname)) {
        return 'fonts';
    }

    if (/^\/storage\/(logos|banners)\/[^/]+\/[^/]+$/.test(url.pathname)) {
        return 'images';
    }

    return null;
}

/**
 * A rule of the browser's static routing (InstallEvent.addRoutes), which
 * it applies without starting the service worker.
 */
export type StaticRoute = {
    condition: { requestMode?: RequestMode; requestMethod?: string };
    source: 'network';
};

/**
 * What never waits for the service worker to start, in the browsers that
 * support static routing (Chromium): every page load, the tap endpoint `/t`
 * above all, and every write. The service worker would let them through
 * anyway (routeFor); this spares them its start-up.
 */
export const NETWORK_ROUTES: StaticRoute[] = [
    { condition: { requestMode: 'navigate' }, source: 'network' },
    // Upper case, as requests carry them: the browser upper-cases a rule's
    // POST/PUT/DELETE but never its PATCH.
    ...['POST', 'PUT', 'PATCH', 'DELETE'].map((requestMethod): StaticRoute => ({
        condition: { requestMethod },
        source: 'network',
    })),
];
