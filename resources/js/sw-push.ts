/**
 * What the service worker shows for a push, and where a tap on it goes
 * (CHW-41). Kept out of sw.ts so it can be tested: a push is the server's
 * WebPushMessage (title, body, icon, badge, tag, data.url), and only a page
 * of this app is ever opened from it.
 */

export type ShownNotification = {
    title: string;
    options: NotificationOptions;
};

const HOME = '/app';

/** The notification a push carries; punchcard's name alone if it carries nothing readable. */
export function notificationFrom(payload: unknown): ShownNotification {
    const message =
        typeof payload === 'object' && payload !== null
            ? (payload as Record<string, unknown>)
            : {};
    const text = (value: unknown): string | undefined =>
        typeof value === 'string' && value !== '' ? value : undefined;
    const data =
        typeof message.data === 'object' && message.data !== null
            ? (message.data as Record<string, unknown>)
            : {};

    return {
        title: text(message.title) ?? 'punchcard',
        options: {
            body: text(message.body),
            icon: text(message.icon),
            badge: text(message.badge),
            tag: text(message.tag),
            data: { url: text(data.url) ?? HOME },
        },
    };
}

/** The page a tap on a notification opens: its own, on this app only, else My cards. */
export function pageToOpen(data: unknown, origin: string): string {
    const url =
        typeof data === 'object' && data !== null
            ? (data as Record<string, unknown>).url
            : undefined;

    if (typeof url !== 'string') {
        return new URL(HOME, origin).href;
    }

    try {
        const page = new URL(url, origin);

        return page.origin === origin ? page.href : new URL(HOME, origin).href;
    } catch {
        return new URL(HOME, origin).href;
    }
}
