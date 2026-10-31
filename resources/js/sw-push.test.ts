import { describe, expect, it } from 'vite-plus/test';
import { notificationFrom, pageToOpen } from './sw-push';

const origin = 'https://punchcard.ma';

describe('notificationFrom', () => {
    it('shows the push as the server sent it', () => {
        expect(
            notificationFrom({
                title: 'Café Hafa',
                body: 'Your reward is ready: a free coffee',
                icon: '/icons/192.png',
                tag: 'reward-7',
                data: { url: '/app/cards/3' },
            }),
        ).toEqual({
            title: 'Café Hafa',
            options: {
                body: 'Your reward is ready: a free coffee',
                icon: '/icons/192.png',
                badge: undefined,
                tag: 'reward-7',
                data: { url: '/app/cards/3' },
            },
        });
    });

    it('still shows something for a push it cannot read, leading home', () => {
        expect(notificationFrom(null)).toEqual({
            title: 'punchcard',
            options: {
                body: undefined,
                icon: undefined,
                badge: undefined,
                tag: undefined,
                data: { url: '/app' },
            },
        });
        expect(notificationFrom({ title: 7, data: 'x' }).title).toBe(
            'punchcard',
        );
    });
});

describe('pageToOpen', () => {
    it('opens the page the notification names, on this app', () => {
        expect(pageToOpen({ url: '/app/cards/3' }, origin)).toBe(
            'https://punchcard.ma/app/cards/3',
        );
        expect(
            pageToOpen({ url: 'https://punchcard.ma/rewards' }, origin),
        ).toBe('https://punchcard.ma/rewards');
    });

    it('never opens another site, nor anything it cannot read', () => {
        expect(pageToOpen({ url: 'https://evil.example/app' }, origin)).toBe(
            'https://punchcard.ma/app',
        );
        expect(pageToOpen({ url: '//evil.example/app' }, origin)).toBe(
            'https://punchcard.ma/app',
        );
        expect(pageToOpen({ url: 'javascript:alert(1)' }, origin)).toBe(
            'https://punchcard.ma/app',
        );
        expect(pageToOpen(undefined, origin)).toBe('https://punchcard.ma/app');
    });
});
