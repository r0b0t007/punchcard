import { useSyncExternalStore } from 'react';

/**
 * The app's install sheet (CHW-35, C13, pwa-conventions skill): offered on
 * the stamp screen from the customer's second visit on (DescribeTap), never
 * in the installed app, and at most once a month on a browser.
 *
 * - Chromium: once the browser offers its own prompt (`beforeinstallprompt`:
 *   installable, not installed), the sheet's Install button opens it. On the
 *   customer app's pages, the prompt is kept from the page's first moment
 *   (app.tsx imports this first) and held back, so the browser's own banner
 *   never shows before the second visit; punchcard Pro keeps the browser's.
 * - Safari on an iPhone or iPad, which has no prompt: the Add to Home Screen
 *   steps. Safari can't see the installed app, so only the snooze stops them.
 * - Anything else: nothing.
 */

/** Chrome's install prompt, which TypeScript's DOM types don't have. */
type InstallPromptEvent = Event & {
    prompt: () => Promise<void>;
    userChoice: Promise<{ outcome: 'accepted' | 'dismissed' }>;
};

export type InstallOffer = 'ios' | 'android' | null;

/** The customer app's manifest (UsePwa), linked on its pages. */
const CUSTOMER_MANIFEST = '/manifest-customer.webmanifest';
const SNOOZE_KEY = 'punchcard-install-sheet';
const SNOOZE_MS = 30 * 86_400_000;

let deferred: InstallPromptEvent | null = null;
const listeners = new Set<() => void>();

function changed(prompt: InstallPromptEvent | null): void {
    deferred = prompt;
    listeners.forEach((listener) => listener());
}

/** Whether a page holds the browser's prompt back for the sheet: the customer app's only. */
export function holdsBackPrompt(manifest: string | null): boolean {
    return manifest === CUSTOMER_MANIFEST;
}

if (typeof window !== 'undefined') {
    window.addEventListener('beforeinstallprompt', (event) => {
        const manifest = document
            .querySelector('link[rel="manifest"]')
            ?.getAttribute('href');

        if (holdsBackPrompt(manifest ?? null)) {
            event.preventDefault();
            changed(event as InstallPromptEvent);
        }
    });
    window.addEventListener('appinstalled', () => changed(null));
}

/** The browser's install prompt, once it offers one. */
export function useInstallPrompt(): InstallPromptEvent | null {
    return useSyncExternalStore(
        (listener) => {
            listeners.add(listener);

            return () => listeners.delete(listener);
        },
        () => deferred,
        () => null,
    );
}

/**
 * Opens the browser's prompt (once: a prompt can't be shown twice), then
 * puts the sheet away for a month, whatever the answer: once installed the
 * browser offers no prompt, and if the app is removed, a month on it asks
 * again. Never fails.
 */
export async function install(): Promise<void> {
    const prompt = deferred;

    if (!prompt) {
        return;
    }

    changed(null);

    try {
        await prompt.prompt();
        await prompt.userChoice;
    } catch {
        // Shown already, or refused by the browser: nothing more to do.
    } finally {
        snooze(Date.now());
    }
}

/**
 * Safari on an iPhone or iPad (which asks for desktop sites, as a Mac with
 * a touch screen), not another browser or an app's own browser: Share → Add
 * to Home Screen.
 */
function isIosSafari(userAgent: string, touchPoints: number): boolean {
    const apple =
        /iP(hone|ad|od)/.test(userAgent) ||
        (/Macintosh/.test(userAgent) && touchPoints > 1);

    return (
        apple &&
        /Version\/[\d.]+.* Safari\//.test(userAgent) &&
        !/CriOS|FxiOS|EdgiOS|OPiOS|GSA\//.test(userAgent)
    );
}

/** Which sheet to offer, if any. */
export function installOffer({
    offerInstall,
    standalone,
    userAgent,
    touchPoints,
    hasPrompt,
    snoozedUntil,
    now,
}: {
    /** The stamp is the customer's second visit or later (DescribeTap). */
    offerInstall: boolean;
    /** Running as the installed app. */
    standalone: boolean;
    userAgent: string;
    /** navigator.maxTouchPoints: tells an iPad from a Mac. */
    touchPoints: number;
    hasPrompt: boolean;
    snoozedUntil: number | null;
    now: number;
}): InstallOffer {
    if (
        !offerInstall ||
        standalone ||
        (snoozedUntil !== null && now < snoozedUntil)
    ) {
        return null;
    }

    if (hasPrompt) {
        return 'android';
    }

    return isIosSafari(userAgent, touchPoints) ? 'ios' : null;
}

/** When a sheet put away at `now` may come back. */
export function snoozeUntil(now: number): number {
    return now + SNOOZE_MS;
}

/** Whether this page runs as the installed app. */
export function isStandalone(): boolean {
    return (
        window.matchMedia('(display-mode: standalone)').matches ||
        (navigator as Navigator & { standalone?: boolean }).standalone === true
    );
}

/** Until when the sheet is put away on this browser, if it is. */
export function snoozedUntil(): number | null {
    try {
        return Number(localStorage.getItem(SNOOZE_KEY)) || null;
    } catch {
        return null;
    }
}

/** Puts the sheet away for a month on this browser. */
export function snooze(now: number): void {
    try {
        localStorage.setItem(SNOOZE_KEY, String(snoozeUntil(now)));
    } catch {
        // Private mode or storage blocked: the sheet may come back sooner.
    }
}
