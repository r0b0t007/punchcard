import { afterEach, describe, expect, it, vi } from 'vite-plus/test';
import {
    applicationServerKey,
    forgetThisPhone,
    pushStateFrom,
    thisAccounts,
    turnOff,
} from '@/lib/push';

/** The app's key in the tests, and the bytes a subscription made with it holds. */
const appKey = 'AQID-_8';
const appKeyBytes = new Uint8Array([1, 2, 3, 251, 255]).buffer;

/**
 * No DOM in unit tests: just the push API this phone has, its subscription
 * (or none), and the app's answer to fetch.
 */
function phoneWith(
    subscription: {
        endpoint: string;
        unsubscribe: () => Promise<boolean>;
    } | null,
    answer: { ok: boolean },
) {
    const fetch = vi.fn(() => Promise.resolve(answer));
    vi.stubGlobal('window', { PushManager: {}, Notification: {} });
    vi.stubGlobal('document', { cookie: '' });
    vi.stubGlobal('fetch', fetch);
    vi.stubGlobal('navigator', {
        userAgent: '',
        maxTouchPoints: 0,
        serviceWorker: {
            getRegistration: () =>
                Promise.resolve({
                    pushManager: {
                        getSubscription: () => Promise.resolve(subscription),
                    },
                }),
        },
    });

    return fetch;
}

const browser = {
    supported: true,
    iosSafari: false,
    standalone: false,
    permission: 'default' as NotificationPermission,
    subscribed: false,
};

describe('pushStateFrom', () => {
    it('is on only when allowed and subscribed', () => {
        expect(
            pushStateFrom({
                ...browser,
                permission: 'granted',
                subscribed: true,
            }),
        ).toBe('on');
        expect(pushStateFrom({ ...browser, permission: 'granted' })).toBe(
            'off',
        );
        expect(pushStateFrom(browser)).toBe('off');
    });

    it('says blocked when the customer said no in the browser', () => {
        expect(pushStateFrom({ ...browser, permission: 'denied' })).toBe(
            'blocked',
        );
    });

    it('points iPhone Safari to the home screen, where the installed app can have them', () => {
        expect(
            pushStateFrom({ ...browser, supported: false, iosSafari: true }),
        ).toBe('ios-needs-install');
        expect(
            pushStateFrom({
                ...browser,
                iosSafari: true,
                standalone: true,
                permission: 'granted',
                subscribed: true,
            }),
        ).toBe('on');
    });

    it('offers nothing where the browser has no web push', () => {
        expect(pushStateFrom({ ...browser, supported: false })).toBe(
            'unsupported',
        );
    });
});

describe('applicationServerKey', () => {
    it('decodes the base64url VAPID key, padding included', () => {
        expect(Array.from(applicationServerKey('AQID-_8'))).toEqual([
            1, 2, 3, 251, 255,
        ]);
    });
});

describe('thisAccounts', () => {
    const phone = {
        key: appKeyBytes,
        publicKey: appKey,
        fingerprint: 'this-phone',
        phones: ['another-phone', 'this-phone'],
    };

    it("is this account's phone when the app keeps it for them, with its current key", () => {
        expect(thisAccounts(phone)).toBe(true);
        // A browser that doesn't say its key.
        expect(thisAccounts({ ...phone, key: null })).toBe(true);
    });

    it('is not when the phone was turned on by someone else signed in here', () => {
        expect(thisAccounts({ ...phone, phones: ['another-phone'] })).toBe(
            false,
        );
    });

    it("is not when the app's key changed since it was turned on: it would hear nothing", () => {
        expect(
            thisAccounts({
                ...phone,
                key: new Uint8Array([1, 2, 3, 251, 0]).buffer,
            }),
        ).toBe(false);
        expect(
            thisAccounts({ ...phone, key: new Uint8Array([1, 2, 3]).buffer }),
        ).toBe(false);
    });
});

describe('turning off and signing out', () => {
    afterEach(() => {
        vi.unstubAllGlobals();
    });

    it('keeps the phone on when the app could not forget it', async () => {
        const unsubscribe = vi.fn(() => Promise.resolve(true));
        phoneWith(
            { endpoint: 'https://push.example/phone', unsubscribe },
            { ok: false },
        );

        await expect(turnOff(appKey, [])).rejects.toThrow();
        expect(unsubscribe).not.toHaveBeenCalled();
    });

    it('unsubscribes once the app forgot it', async () => {
        const unsubscribe = vi.fn(() => Promise.resolve(true));
        const fetch = phoneWith(
            { endpoint: 'https://push.example/phone', unsubscribe },
            { ok: true },
        );

        await turnOff(appKey, []);

        expect(fetch).toHaveBeenCalledWith(
            '/app/push/subscriptions',
            expect.objectContaining({ method: 'DELETE' }),
        );
        expect(unsubscribe).toHaveBeenCalledOnce();
    });

    it('forgets this phone at sign-out, the app reached or not, and never fails the sign-out', async () => {
        const unsubscribe = vi.fn(() => Promise.resolve(true));
        phoneWith(
            { endpoint: 'https://push.example/phone', unsubscribe },
            { ok: false },
        );

        await forgetThisPhone();
        expect(unsubscribe).toHaveBeenCalledOnce();

        vi.stubGlobal('navigator', {
            serviceWorker: {
                getRegistration: () => Promise.reject(new Error('no worker')),
            },
        });
        vi.spyOn(console, 'error').mockImplementation(() => {});
        await expect(forgetThisPhone()).resolves.toBeUndefined();
    });
});
