import { describe, expect, it, vi } from 'vite-plus/test';
import {
    hasOfflineFallback,
    isMyCardsVisit,
    loadInFull,
    NETWORK_ROUTES,
    networkOrOffline,
    preloadedOrFetched,
    routeFor,
} from '@/sw-routes';

const origin = 'https://punchcard.test';
const route = (method: string, path: string, from = origin) =>
    routeFor(method, new URL(path, from), origin);

describe('routeFor', () => {
    it('keeps a font once a page used it', () => {
        expect(
            route('GET', '/build/assets/instrument-sans-latin-400.woff2'),
        ).toBe('fonts');
    });

    it('refreshes logos and banners in the background', () => {
        expect(route('GET', '/storage/logos/12/abc.png')).toBe('images');
        expect(route('GET', '/storage/banners/12/abc.webp')).toBe('images');
    });

    it('never touches the tap endpoint, sign-in, pages or data', () => {
        for (const path of [
            '/t?e=ABC&c=DEF',
            '/t/result',
            '/login',
            '/app',
            '/api/me/cards',
            '/business',
            '/business/cards',
            '/storage/logos/../private/key',
            '/build/assets/app.js',
        ]) {
            expect(route('GET', path)).toBeNull();
        }
    });

    it('never touches a write, even to a cached address', () => {
        for (const method of ['POST', 'PUT', 'PATCH', 'DELETE']) {
            expect(route(method, '/storage/logos/1/a.png')).toBeNull();
        }
    });

    it('never touches another site', () => {
        expect(
            route('GET', '/storage/logos/1/a.png', 'https://cdn.example'),
        ).toBeNull();
    });
});

describe('NETWORK_ROUTES', () => {
    it("sends every page load and every write past the service worker, but My cards'", () => {
        const network = NETWORK_ROUTES.filter(
            (rule) => rule.source === 'network',
        ).map((rule) => rule.condition);

        expect(network).toContainEqual({ requestMode: 'navigate' });

        for (const requestMethod of ['POST', 'PUT', 'PATCH', 'DELETE']) {
            expect(network).toContainEqual({ requestMethod });
        }
    });

    it("hands My cards' page loads to the service worker first, for their offline page", () => {
        const firstNavigate = NETWORK_ROUTES.findIndex(
            (rule) =>
                rule.condition.requestMode === 'navigate' &&
                rule.source === 'network',
        );
        const myCards = NETWORK_ROUTES.filter(
            (rule) => rule.source === 'fetch-event',
        );

        expect(myCards.map((rule) => rule.condition)).toEqual([
            { requestMode: 'navigate', urlPattern: '/app' },
            { requestMode: 'navigate', urlPattern: '/app/*' },
        ]);

        // The rules apply in order: theirs before the one for every page.
        for (const rule of myCards) {
            expect(NETWORK_ROUTES.indexOf(rule)).toBeLessThan(firstNavigate);
        }
    });
});

describe('hasOfflineFallback', () => {
    const fallback = (path: string, from = origin) =>
        hasOfflineFallback(new URL(path, from), origin);

    it('covers My cards and a card', () => {
        expect(fallback('/app')).toBe(true);
        expect(fallback('/app/cards/7')).toBe(true);
    });

    it('covers nothing else', () => {
        for (const path of [
            '/',
            '/apple',
            '/t?e=00',
            '/rewards',
            '/business',
        ]) {
            expect(fallback(path)).toBe(false);
        }

        expect(fallback('/app', 'https://cdn.example')).toBe(false);
    });
});

describe('networkOrOffline', () => {
    const offline = () => Promise.resolve(new Response('offline page'));

    it('answers from the network when it can', async () => {
        const answer = await networkOrOffline(
            () => Promise.resolve(new Response('my cards')),
            offline,
        );

        expect(await answer.text()).toBe('my cards');
    });

    it('answers the offline page when the network fails', async () => {
        const answer = await networkOrOffline(
            () => Promise.reject(new TypeError('Failed to fetch')),
            offline,
        );

        expect(await answer.text()).toBe('offline page');
    });

    it('waits for a slow answer rather than calling it offline', async () => {
        const answer = await networkOrOffline(
            () =>
                new Promise<Response>((resolve) =>
                    setTimeout(() => resolve(new Response('my cards')), 30),
                ),
            offline,
        );

        expect(await answer.text()).toBe('my cards');
    });
});

describe('preloadedOrFetched', () => {
    const fetchAgain = () => Promise.resolve(new Response('fetched again'));

    it('answers with the request the browser started while the worker booted', async () => {
        const answer = await preloadedOrFetched(
            Promise.resolve(new Response('preloaded')),
            fetchAgain,
        );

        expect(await answer.text()).toBe('preloaded');
    });

    it('fetches the page itself when nothing was preloaded', async () => {
        expect(
            await (
                await preloadedOrFetched(Promise.resolve(undefined), fetchAgain)
            ).text(),
        ).toBe('fetched again');
        expect(
            await (await preloadedOrFetched(undefined, fetchAgain)).text(),
        ).toBe('fetched again');
    });

    it('never sends the request again when the preload failed (a tap may have stamped already)', async () => {
        const again = vi.fn(fetchAgain);

        await expect(
            preloadedOrFetched(
                Promise.reject(new TypeError('Failed to fetch')),
                again,
            ),
        ).rejects.toThrow('Failed to fetch');
        expect(again).not.toHaveBeenCalled();
    });
});

describe('isMyCardsVisit', () => {
    const visit = (
        path: string,
        headers: Record<string, string> = {},
        method = 'GET',
    ) =>
        isMyCardsVisit(
            method,
            new URL(path, origin),
            new Headers({ 'X-Inertia': 'true', ...headers }),
            origin,
        );

    it('covers a tap on My cards or a card in the app', () => {
        expect(visit('/app')).toBe(true);
        expect(visit('/app/cards/7')).toBe(true);
    });

    it('leaves prefetches, partial reloads and writes alone', () => {
        expect(visit('/app', { Purpose: 'prefetch' })).toBe(false);
        expect(
            visit('/app/cards/7', {
                'X-Inertia-Partial-Component': 'app/card',
                'X-Inertia-Partial-Data': 'translations',
            }),
        ).toBe(false);
        expect(visit('/app', {}, 'POST')).toBe(false);
    });

    it('covers no other page, nor a request that is no Inertia visit', () => {
        expect(visit('/rewards')).toBe(false);
        expect(
            isMyCardsVisit(
                'GET',
                new URL('/app', origin),
                new Headers(),
                origin,
            ),
        ).toBe(false);
    });
});

describe('loadInFull', () => {
    it("is Inertia's answer for a page load of the same address", () => {
        const answer = loadInFull(new URL('/app/cards/7', origin));

        expect(answer.status).toBe(409);
        expect(answer.headers.get('X-Inertia-Location')).toBe(
            `${origin}/app/cards/7`,
        );
    });
});
