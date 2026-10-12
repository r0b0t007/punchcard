import { describe, expect, it } from 'vite-plus/test';
import { NETWORK_ROUTES, routeFor } from '@/sw-routes';

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
    it('sends every page load and every write past the service worker', () => {
        const conditions = NETWORK_ROUTES.map((rule) => {
            expect(rule.source).toBe('network');

            return rule.condition;
        });

        expect(conditions).toContainEqual({ requestMode: 'navigate' });

        for (const requestMethod of ['POST', 'PUT', 'PATCH', 'DELETE']) {
            expect(conditions).toContainEqual({ requestMethod });
        }
    });
});
