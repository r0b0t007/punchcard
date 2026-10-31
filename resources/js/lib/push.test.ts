import { describe, expect, it } from 'vite-plus/test';
import { applicationServerKey, pushStateFrom } from '@/lib/push';

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
