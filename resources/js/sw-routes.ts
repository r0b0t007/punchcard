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
    condition: {
        requestMode?: RequestMode;
        requestMethod?: string;
        urlPattern?: string;
    };
    source: 'network' | 'fetch-event';
};

/**
 * The pages of My cards (C5, C6), which the service worker answers with
 * the cards kept on the phone when they can't load (CHW-35).
 */
export function hasOfflineFallback(url: URL, origin: string): boolean {
    return url.origin === origin && /^\/app(\/|$)/.test(url.pathname);
}

/**
 * A page of My cards from the network, else (offline) the offline page
 * (CHW-35). Only a failure falls back: a slow answer is still the page,
 * however long the browser waits for it.
 */
export async function networkOrOffline(
    fromNetwork: () => Promise<Response>,
    offlinePage: () => Promise<Response | undefined>,
): Promise<Response> {
    try {
        return await fromNetwork();
    } catch {
        return (await offlinePage()) ?? Response.error();
    }
}

/**
 * A page load from the request the browser started while the service
 * worker booted (navigation preload), else fetched now: never both.
 */
export async function preloadedOrFetched(
    preloadResponse: Promise<Response | undefined> | undefined,
    fetchAgain: () => Promise<Response>,
): Promise<Response> {
    return (await preloadResponse) ?? fetchAgain();
}

/**
 * What never waits for the service worker to start, in the browsers that
 * support static routing (Chromium): every page load, the tap endpoint `/t`
 * above all, but My cards' (which go to the service worker, for their
 * offline fallback; the rules apply in order), and every write. The
 * service worker would let them through anyway (routeFor); this spares
 * them its start-up.
 */
export const NETWORK_ROUTES: StaticRoute[] = [
    ...['/app', '/app/*'].map((urlPattern): StaticRoute => ({
        condition: { requestMode: 'navigate', urlPattern },
        source: 'fetch-event',
    })),
    { condition: { requestMode: 'navigate' }, source: 'network' },
    // Upper case, as requests carry them: the browser upper-cases a rule's
    // POST/PUT/DELETE but never its PATCH.
    ...['POST', 'PUT', 'PATCH', 'DELETE'].map((requestMethod): StaticRoute => ({
        condition: { requestMethod },
        source: 'network',
    })),
];
