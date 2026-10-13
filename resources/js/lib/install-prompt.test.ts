import { describe, expect, it } from 'vite-plus/test';
import { installOffer, snoozeUntil } from '@/lib/install-prompt';

const IPHONE_SAFARI =
    'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1';
const IPHONE_CHROME =
    'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/129.0.6668.69 Mobile/15E148 Safari/604.1';
const IPHONE_INSTAGRAM =
    'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 Instagram 350.0.0.0.0';
const ANDROID_CHROME =
    'Mozilla/5.0 (Linux; Android 14; Pixel 7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Mobile Safari/537.36';

const now = new Date('2026-10-13T10:00:00Z').getTime();
const offer = (overrides: Partial<Parameters<typeof installOffer>[0]> = {}) =>
    installOffer({
        offerInstall: true,
        standalone: false,
        userAgent: IPHONE_SAFARI,
        hasPrompt: false,
        snoozedUntil: null,
        now,
        ...overrides,
    });

describe('installOffer', () => {
    it('shows the steps in Safari on an iPhone, where the browser has no prompt', () => {
        expect(offer()).toBe('ios');
    });

    it('never in another browser on an iPhone, whose steps differ', () => {
        expect(offer({ userAgent: IPHONE_CHROME })).toBeNull();
        expect(offer({ userAgent: IPHONE_INSTAGRAM })).toBeNull();
    });

    it("offers the browser's own prompt once it has one (installable, not installed)", () => {
        expect(offer({ userAgent: ANDROID_CHROME, hasPrompt: true })).toBe(
            'android',
        );
        expect(offer({ userAgent: ANDROID_CHROME })).toBeNull();
    });

    it('never in the installed app, nor before the second visit', () => {
        expect(offer({ standalone: true })).toBeNull();
        expect(
            offer({
                userAgent: ANDROID_CHROME,
                hasPrompt: true,
                standalone: true,
            }),
        ).toBeNull();
        expect(offer({ offerInstall: false })).toBeNull();
    });

    it('waits out a snooze, then offers again', () => {
        const snoozed = snoozeUntil(now);

        expect(offer({ snoozedUntil: snoozed })).toBeNull();
        expect(
            offer({ snoozedUntil: snoozed, now: now + 29 * 86_400_000 }),
        ).toBeNull();
        expect(
            offer({ snoozedUntil: snoozed, now: now + 30 * 86_400_000 }),
        ).toBe('ios');
        expect(offer({ snoozedUntil: Infinity })).toBeNull();
    });
});
