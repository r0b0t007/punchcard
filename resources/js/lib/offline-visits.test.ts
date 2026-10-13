import { describe, expect, it } from 'vite-plus/test';
import { fullLoadWhenOffline } from '@/lib/offline-visits';

const origin = 'https://punchcard.test';
const visit = (path: string, method = 'get', prefetch = false) => ({
    method,
    url: new URL(path, origin),
    prefetch,
});

describe('fullLoadWhenOffline', () => {
    it('loads a page of My cards in full, for the service worker to answer offline', () => {
        expect(fullLoadWhenOffline(visit('/app'), true, origin)).toBe(
            `${origin}/app`,
        );
        expect(fullLoadWhenOffline(visit('/app/cards/7'), true, origin)).toBe(
            `${origin}/app/cards/7`,
        );
    });

    it('leaves the failure as it is without a service worker in control', () => {
        expect(fullLoadWhenOffline(visit('/app'), false, origin)).toBeNull();
    });

    it('leaves every other visit alone', () => {
        expect(fullLoadWhenOffline(null, true, origin)).toBeNull();
        expect(fullLoadWhenOffline(visit('/rewards'), true, origin)).toBeNull();
        expect(
            fullLoadWhenOffline(visit('/app', 'post'), true, origin),
        ).toBeNull();
        expect(
            fullLoadWhenOffline(visit('/app', 'get', true), true, origin),
        ).toBeNull();
    });
});
