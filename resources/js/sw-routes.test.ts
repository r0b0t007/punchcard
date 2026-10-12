import { describe, expect, it } from 'vite-plus/test';
import { routeFor } from '@/sw-routes';

const origin = 'https://punchcard.test';
const route = (method: string, path: string, from = origin) =>
    routeFor(method, new URL(path, from), origin);

describe('routeFor', () => {
    it("keeps the customer's cards for offline use", () => {
        expect(route('GET', '/api/me/cards')).toBe('my-cards');
    });

    it('refreshes logos and banners in the background', () => {
        expect(route('GET', '/storage/logos/12/abc.png')).toBe('images');
        expect(route('GET', '/storage/banners/12/abc.webp')).toBe('images');
    });

    it('never touches the tap endpoint, sign-in, pages or business data', () => {
        for (const path of [
            '/t?e=ABC&c=DEF',
            '/t/result',
            '/login',
            '/app',
            '/dashboard',
            '/cards',
            '/storage/logos/../private/key',
        ]) {
            expect(route('GET', path)).toBeNull();
        }
    });

    it('never touches a write, even to a cached address', () => {
        for (const method of ['POST', 'PUT', 'PATCH', 'DELETE']) {
            expect(route(method, '/api/me/cards')).toBeNull();
        }
    });

    it('never touches another site', () => {
        expect(
            route('GET', '/storage/logos/1/a.png', 'https://cdn.example'),
        ).toBeNull();
    });
});
