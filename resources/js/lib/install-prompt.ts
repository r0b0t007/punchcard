import { useSyncExternalStore } from 'react';

/**
 * The app's install sheet (CHW-35, C13, pwa-conventions skill): offered on
 * the stamp screen from the customer's second visit on (DescribeTap), never
 * in the installed app, and at most once a month on a browser.
 *
 * - Chromium: once the browser offers its own prompt (`beforeinstallprompt`:
 *   installable, not installed), the sheet's Install button opens it. Kept
 *   from the page's first moment (app.tsx imports this first), and held back
 *   meanwhile, so the browser's own banner never shows on a first visit.
 * - Safari on an iPhone, which has no prompt: the Add to Home Screen steps.
 *   Safari can't see the installed app, so only the snooze stops them.
 * - Anything else: nothing.
 */

/** Chrome's install prompt, which TypeScript's DOM types don't have. */
type InstallPromptEvent = Event & {
    prompt: () => Promise<void>;
    userChoice: Promise<{ outcome: 'accepted' | 'dismissed' }>;
};

export type InstallOffer = 'ios' | 'android' | null;

const SNOOZE_KEY = 'punchcard-install-sheet';
const SNOOZE_MS = 30 * 86_400_000;
const INSTALLED = 'installed';

let deferred: InstallPromptEvent | null = null;
const listeners = new Set<() => void>();

function changed(prompt: InstallPromptEvent | null): void {
    deferred = prompt;
    listeners.forEach((listener) => listener());
}

if (typeof window !== 'undefined') {
    window.addEventListener('beforeinstallprompt', (event) => {
        event.preventDefault();
        changed(event as InstallPromptEvent);
    });
    window.addEventListener('appinstalled', () => {
        remember(INSTALLED);
        changed(null);
    });
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

/** Opens the browser's prompt (once: a prompt can't be shown twice): installed, never asked again; declined, a month on. */
export async function install(): Promise<void> {
    const prompt = deferred;

    if (!prompt) {
        return;
    }

    changed(null);
    await prompt.prompt();

    if ((await prompt.userChoice).outcome === 'accepted') {
        remember(INSTALLED);
    } else {
        snooze(Date.now());
    }
}

/** Safari on an iPhone or iPad, not another browser or an app's own browser: Share → Add to Home Screen. */
function isIosSafari(userAgent: string): boolean {
    return (
        /iP(hone|ad|od)/.test(userAgent) &&
        /Version\/[\d.]+.* Safari\//.test(userAgent) &&
        !/CriOS|FxiOS|EdgiOS|OPiOS|GSA\//.test(userAgent)
    );
}

/** Which sheet to offer, if any. */
export function installOffer({
    offerInstall,
    standalone,
    userAgent,
    hasPrompt,
    snoozedUntil,
    now,
}: {
    /** The stamp is the customer's second visit or later (DescribeTap). */
    offerInstall: boolean;
    /** Running as the installed app. */
    standalone: boolean;
    userAgent: string;
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

    return isIosSafari(userAgent) ? 'ios' : null;
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

/** Until when the sheet is put away on this browser (forever once installed), if it is. */
export function snoozedUntil(): number | null {
    try {
        const stored = localStorage.getItem(SNOOZE_KEY);

        if (stored === null) {
            return null;
        }

        return stored === INSTALLED ? Infinity : Number(stored) || null;
    } catch {
        return null;
    }
}

/** Puts the sheet away for a month on this browser. */
export function snooze(now: number): void {
    remember(String(snoozeUntil(now)));
}

function remember(value: string): void {
    try {
        localStorage.setItem(SNOOZE_KEY, value);
    } catch {
        // Private mode or storage blocked: the sheet may come back sooner.
    }
}
