import { useCallback, useEffect, useState } from 'react';
import { isIosSafari, isStandalone, snoozeUntil } from '@/lib/install-prompt';
import { subscribe, unsubscribe } from '@/routes/push';

/**
 * Notifications on this phone (CHW-41, design 11): what it can do, and
 * turning them on or off. The browser's own dialog only ever opens from a
 * tap on "Turn on notifications"; nothing here asks on its own.
 *
 * - unsupported: this browser can't show web push;
 * - ios-needs-install: Safari on an iPhone, which only allows it in the app
 *   added to the home screen;
 * - blocked: the customer said no in the browser (only its settings undo it);
 * - off / on: whether this phone is subscribed, for this account, with the
 *   app's current key (thisAccounts).
 */
export type PushState =
    | 'unsupported'
    | 'ios-needs-install'
    | 'blocked'
    | 'off'
    | 'on';

/** The state, from what the browser says (pure, for the tests). */
export function pushStateFrom({
    supported,
    iosSafari,
    standalone,
    permission,
    subscribed,
}: {
    supported: boolean;
    iosSafari: boolean;
    standalone: boolean;
    permission: NotificationPermission;
    subscribed: boolean;
}): PushState {
    if (iosSafari && !standalone) {
        return 'ios-needs-install';
    }

    if (!supported) {
        return 'unsupported';
    }

    if (permission === 'denied') {
        return 'blocked';
    }

    return permission === 'granted' && subscribed ? 'on' : 'off';
}

/**
 * Whether this phone's subscription reaches this account: the app keeps it
 * for them (its endpoint's fingerprint is one of their phones), and it was
 * made with the app's current key (one made before the key changed hears
 * nothing). A browser that doesn't say its key is taken at its word.
 */
export function thisAccounts({
    key,
    publicKey,
    fingerprint,
    phones,
}: {
    key: ArrayBuffer | null;
    publicKey: string;
    fingerprint: string;
    phones: string[];
}): boolean {
    return (
        phones.includes(fingerprint) &&
        (key === null ||
            sameBytes(new Uint8Array(key), applicationServerKey(publicKey)))
    );
}

function sameBytes(left: Uint8Array, right: Uint8Array): boolean {
    return (
        left.length === right.length &&
        left.every((byte, index) => byte === right[index])
    );
}

/** An endpoint as the app lists the account's phones: its SHA-256, in hex. */
async function fingerprintOf(endpoint: string): Promise<string> {
    const digest = await crypto.subtle.digest(
        'SHA-256',
        new TextEncoder().encode(endpoint),
    );

    return Array.from(new Uint8Array(digest), (byte) =>
        byte.toString(16).padStart(2, '0'),
    ).join('');
}

function supportsPush(): boolean {
    return (
        typeof window !== 'undefined' &&
        'serviceWorker' in navigator &&
        'PushManager' in window &&
        'Notification' in window
    );
}

/** How long the app's service worker gets to become active, before a change is said to have failed. */
const WORKER_WAIT_MS = 10_000;

/**
 * The app's service worker once active (a push subscription needs one),
 * registered here if no app page has yet (settings). One that never gets
 * there (an install that failed) fails the change instead of spinning.
 */
async function worker(): Promise<ServiceWorkerRegistration> {
    if (!(await navigator.serviceWorker.getRegistration('/'))) {
        await navigator.serviceWorker.register('/sw.js', { scope: '/' });
    }

    let timer: ReturnType<typeof setTimeout> | undefined;

    try {
        return await Promise.race([
            navigator.serviceWorker.ready,
            new Promise<never>((_, reject) => {
                timer = setTimeout(
                    () =>
                        reject(
                            new Error(
                                'The service worker did not become active.',
                            ),
                        ),
                    WORKER_WAIT_MS,
                );
            }),
        ]);
    } finally {
        clearTimeout(timer);
    }
}

async function subscriptionHere(): Promise<PushSubscription | null> {
    const registration = await navigator.serviceWorker.getRegistration('/');

    return registration ? registration.pushManager.getSubscription() : null;
}

/** This phone's state for this account; a browser that can't even say is one without push. */
async function currentState(
    publicKey: string,
    phones: string[],
): Promise<PushState> {
    try {
        const supported = supportsPush();
        const subscription = supported ? await subscriptionHere() : null;

        return pushStateFrom({
            supported,
            iosSafari: isIosSafari(
                navigator.userAgent,
                navigator.maxTouchPoints,
            ),
            standalone: isStandalone(),
            permission: supported ? Notification.permission : 'default',
            subscribed:
                subscription !== null &&
                thisAccounts({
                    key: subscription.options.applicationServerKey,
                    publicKey,
                    fingerprint: await fingerprintOf(subscription.endpoint),
                    phones,
                }),
        });
    } catch (error) {
        console.error(error);

        return 'unsupported';
    }
}

/** A key as PushManager.subscribe takes it: the base64url VAPID public key, decoded. */
export function applicationServerKey(
    publicKey: string,
): Uint8Array<ArrayBuffer> {
    const base64 = (publicKey + '='.repeat((4 - (publicKey.length % 4)) % 4))
        .replace(/-/g, '+')
        .replace(/_/g, '/');
    const raw = atob(base64);
    const bytes = new Uint8Array(new ArrayBuffer(raw.length));

    for (let index = 0; index < raw.length; index++) {
        bytes[index] = raw.charCodeAt(index);
    }

    return bytes;
}

/** A JSON call to the app, as a page of it (its session and XSRF cookie). */
async function send(
    method: 'POST' | 'DELETE',
    url: string,
    body: unknown,
): Promise<boolean> {
    const token = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/)?.[1];
    const response = await fetch(url, {
        method,
        credentials: 'same-origin',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            ...(token ? { 'X-XSRF-TOKEN': decodeURIComponent(token) } : {}),
        },
        body: JSON.stringify(body),
    });

    return response.ok;
}

/**
 * From a tap: the browser's dialog, then this phone's subscription, kept by
 * the app for this account. One made with an older key is replaced (it
 * would hear nothing); one another account had moves to this one.
 */
async function turnOn(publicKey: string, phones: string[]): Promise<PushState> {
    if ((await Notification.requestPermission()) !== 'granted') {
        return currentState(publicKey, phones);
    }

    const registration = await worker();
    const key = applicationServerKey(publicKey);
    let subscription = await registration.pushManager.getSubscription();
    const madeBefore = subscription?.options.applicationServerKey;

    if (
        subscription &&
        madeBefore &&
        !sameBytes(new Uint8Array(madeBefore), key)
    ) {
        // The old endpoint is forgotten by the app too, where it can (another account's stays theirs until it is gone).
        await send('DELETE', unsubscribe.url(), {
            endpoint: subscription.endpoint,
        }).catch(() => false);
        await subscription.unsubscribe();
        subscription = null;
    }

    subscription ??= await registration.pushManager.subscribe({
        userVisibleOnly: true,
        applicationServerKey: key,
    });
    const encoding = PushManager.supportedContentEncodings?.includes(
        'aes128gcm',
    )
        ? 'aes128gcm'
        : 'aesgcm';

    if (
        !(await send('POST', subscribe.url(), {
            ...subscription.toJSON(),
            contentEncoding: encoding,
        }))
    ) {
        // Not kept: no half-on phone.
        await subscription.unsubscribe();
        throw new Error("The app did not keep this phone's subscription.");
    }

    // Kept for this account now, whatever the page's list said when it loaded.
    return currentState(publicKey, [
        ...phones,
        await fingerprintOf(subscription.endpoint),
    ]);
}

/**
 * This phone hears nothing more: forgotten by the app, then unsubscribed.
 * If the app can't be told, the phone stays as it is (still on), never
 * half off.
 */
export async function turnOff(
    publicKey: string,
    phones: string[],
): Promise<PushState> {
    const subscription = await subscriptionHere();

    if (subscription) {
        if (
            !(await send('DELETE', unsubscribe.url(), {
                endpoint: subscription.endpoint,
            }))
        ) {
            throw new Error('The app did not forget this phone.');
        }

        await subscription.unsubscribe();
    }

    return currentState(publicKey, phones);
}

const ASK_KEY = 'punchcard-push-ask';

/**
 * Whether the ask under a moment (11a) is for this phone: it could turn
 * them on (on an iPhone, once added to the home screen). Not where it has
 * them, said no, or can't have them.
 */
export function asksFor(state: PushState | null): boolean {
    return state === 'off' || state === 'ios-needs-install';
}

/** Whether "Not now" still holds the ask away at `now`. */
export function askHeldAway(putAwayUntil: number | null, now: number): boolean {
    return putAwayUntil !== null && putAwayUntil > now;
}

/** Until when the ask is put away on this browser, if it is. */
export function askPutAwayUntil(): number | null {
    try {
        return Number(localStorage.getItem(ASK_KEY)) || null;
    } catch {
        return null;
    }
}

/** "Not now": the ask stays away for a month on this browser, as the install sheet does. */
export function putAskAway(now: number): void {
    try {
        localStorage.setItem(ASK_KEY, String(snoozeUntil(now)));
    } catch {
        // Private mode or storage blocked: the ask may come back sooner.
    }
}

/**
 * Signing out: this phone hears nothing more for this account. Unsubscribed
 * here (the push service then says "gone" and the app forgets the row), and
 * the app told too while the session still holds. Never stops the sign-out.
 */
export async function forgetThisPhone(): Promise<void> {
    try {
        const subscription = supportsPush() ? await subscriptionHere() : null;

        if (subscription) {
            void send('DELETE', unsubscribe.url(), {
                endpoint: subscription.endpoint,
            }).catch(() => false);
            await subscription.unsubscribe();
        }
    } catch (error) {
        console.error(error);
    }
}

/**
 * This phone's notifications, read once the page is on the phone (never
 * while it renders on the server), and turned on or off from a tap. Null
 * until read; nothing at all without the app's key. `phones` are the
 * account's, as the app lists them (DescribePrivacy).
 */
export function usePush(
    publicKey: string | null,
    phones: string[],
): {
    state: PushState | null;
    busy: boolean;
    /** The last change could not be made (no push service, the app unreachable). */
    failed: boolean;
    turnOn: () => Promise<void>;
    turnOff: () => Promise<void>;
} {
    const [state, setState] = useState<PushState | null>(null);
    const [busy, setBusy] = useState(false);
    const [failed, setFailed] = useState(false);
    const listed = phones.join(',');

    useEffect(() => {
        if (publicKey === null) {
            return;
        }

        let current = true;
        void currentState(
            publicKey,
            listed === '' ? [] : listed.split(','),
        ).then((read) => current && setState(read));

        return () => {
            current = false;
        };
    }, [publicKey, listed]);

    const run = useCallback(
        async (change: (key: string) => Promise<PushState>) => {
            if (publicKey === null) {
                return;
            }

            setBusy(true);
            setFailed(false);

            try {
                setState(await change(publicKey));
            } catch (error) {
                console.error(error);
                setFailed(true);
                setState(await currentState(publicKey, phones));
            } finally {
                setBusy(false);
            }
        },
        [publicKey, phones],
    );

    return {
        state: publicKey === null ? null : state,
        busy,
        failed,
        turnOn: () => run((key) => turnOn(key, phones)),
        turnOff: () => run((key) => turnOff(key, phones)),
    };
}
