import { useCallback, useEffect, useState } from 'react';
import { isIosSafari, isStandalone } from '@/lib/install-prompt';
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
 * - off / on: whether this phone is subscribed.
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

function supportsPush(): boolean {
    return (
        typeof window !== 'undefined' &&
        'serviceWorker' in navigator &&
        'PushManager' in window &&
        'Notification' in window
    );
}

/**
 * The app's service worker once active (a push subscription needs one),
 * registered here if no app page has yet (settings).
 */
async function worker(): Promise<ServiceWorkerRegistration> {
    if (!(await navigator.serviceWorker.getRegistration('/'))) {
        await navigator.serviceWorker.register('/sw.js', { scope: '/' });
    }

    return navigator.serviceWorker.ready;
}

async function currentState(): Promise<PushState> {
    const supported = supportsPush();
    const registration = supported
        ? await navigator.serviceWorker.getRegistration('/')
        : undefined;
    const subscription = registration
        ? await registration.pushManager.getSubscription()
        : null;

    return pushStateFrom({
        supported,
        iosSafari: isIosSafari(navigator.userAgent, navigator.maxTouchPoints),
        standalone: isStandalone(),
        permission: supported ? Notification.permission : 'default',
        subscribed: subscription !== null,
    });
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

/** From a tap: the browser's dialog, then this phone's subscription, kept by the app. */
async function turnOn(publicKey: string): Promise<PushState> {
    if ((await Notification.requestPermission()) !== 'granted') {
        return currentState();
    }

    const registration = await worker();
    const subscription =
        (await registration.pushManager.getSubscription()) ??
        (await registration.pushManager.subscribe({
            userVisibleOnly: true,
            applicationServerKey: applicationServerKey(publicKey),
        }));
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

    return currentState();
}

/** This phone hears nothing more: unsubscribed, and forgotten by the app. */
async function turnOff(): Promise<PushState> {
    const registration = await navigator.serviceWorker.getRegistration('/');
    const subscription = registration
        ? await registration.pushManager.getSubscription()
        : null;

    if (subscription) {
        await send('DELETE', unsubscribe.url(), {
            endpoint: subscription.endpoint,
        });
        await subscription.unsubscribe();
    }

    return currentState();
}

/**
 * This phone's notifications, read once the page is on the phone (never
 * while it renders on the server), and turned on or off from a tap. Null
 * until read; nothing at all without the app's key.
 */
export function usePush(publicKey: string | null): {
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

    useEffect(() => {
        if (publicKey === null) {
            return;
        }

        let current = true;
        void currentState().then((read) => current && setState(read));

        return () => {
            current = false;
        };
    }, [publicKey]);

    const run = useCallback(async (change: () => Promise<PushState>) => {
        setBusy(true);
        setFailed(false);

        try {
            setState(await change());
        } catch (error) {
            console.error(error);
            setFailed(true);
            setState(await currentState().catch(() => 'unsupported' as const));
        } finally {
            setBusy(false);
        }
    }, []);

    return {
        state: publicKey === null ? null : state,
        busy,
        failed,
        turnOn: () =>
            publicKey === null
                ? Promise.resolve()
                : run(() => turnOn(publicKey)),
        turnOff: () => run(turnOff),
    };
}
