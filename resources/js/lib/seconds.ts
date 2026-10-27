import { useSyncExternalStore } from 'react';

/**
 * One ticker for the whole page, on each whole second of the phone's clock,
 * however many things read it (a live clock, every "last tap" line). A tick
 * is scheduled to the next second boundary each time, so a late timer never
 * shows a second twice or skips one, and the time is read again the moment
 * the page comes back from the background. The time is kept to the whole
 * second, so reading it twice within one gives the same value.
 */
const listeners = new Set<() => void>();
let timer: ReturnType<typeof setTimeout> | undefined;
let now = currentSecond();

function currentSecond(): number {
    return Math.floor(Date.now() / 1000) * 1000;
}

function tick(): void {
    now = currentSecond();
    listeners.forEach((notify) => notify());
}

function schedule(): void {
    // A few ms past the boundary, so the new second has begun when it is read.
    timer = setTimeout(
        () => {
            tick();

            // The last reader may have left during the tick: no orphan timer then.
            if (listeners.size > 0) {
                schedule();
            }
        },
        1000 - (Date.now() % 1000) + 5,
    );
}

function onVisible(): void {
    if (document.visibilityState === 'visible') {
        tick();
    }
}

export function subscribeToSeconds(listener: () => void): () => void {
    listeners.add(listener);

    if (listeners.size === 1) {
        // The first reader in a while: the time kept may be from long ago.
        now = currentSecond();
        schedule();
        document.addEventListener('visibilitychange', onVisible);
    }

    return () => {
        listeners.delete(listener);

        if (listeners.size === 0) {
            clearTimeout(timer);
            timer = undefined;
            document.removeEventListener('visibilitychange', onVisible);
        }
    };
}

/** The time kept; with nobody subscribed it is read afresh, so a reader's first render is never stale. */
export function readSecond(): number {
    if (listeners.size === 0) {
        now = currentSecond();
    }

    return now;
}

/** No time while the server renders: its clock (and timezone) is not the phone's. */
function readOnServer(): null {
    return null;
}

/**
 * The phone's time (ms), updated on each whole second; null while the page
 * is rendered on the server and hydrated, so the markup never disagrees.
 */
export function useSecond(): number | null {
    return useSyncExternalStore(subscribeToSeconds, readSecond, readOnServer);
}
