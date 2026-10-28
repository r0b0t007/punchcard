import { useEffect, useRef, useState, useSyncExternalStore } from 'react';

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

/**
 * Where a countdown's deadline goes when a new count arrives (a poll): for
 * the same arming, the earlier of the two, so the count never steps back up
 * and a slow answer never carries it past the server's end; for a new
 * arming (the server's count went up), the new one.
 */
export function nextDeadline(
    current: number,
    arrived: number,
    rearmed: boolean,
): number {
    return rearmed ? arrived : Math.min(current, arrived);
}

/**
 * The seconds left of a server's count (secondsLeft), on the page's one
 * ticker: a scan's window (S2), an armed stamper (S1). The server rounds its
 * count up and the answer takes a moment to come, so the deadline starts a
 * second short, from a whole second: 0 comes a little early, never after the
 * server has stopped. A new count (refreshedBy changes, as each poll's data
 * does) moves the deadline through nextDeadline: a new arming only when the
 * server's own count went up, never judged by when the answer came. What
 * the server sent while the page is rendered there.
 */
export function useSecondsLeft(
    secondsLeft: number,
    refreshedBy?: unknown,
): number {
    const [deadline, setDeadline] = useState(() => deadlineFor(secondsLeft));
    const sent = useRef(secondsLeft);
    const now = useSecond();

    useEffect(() => {
        const rearmed = secondsLeft > sent.current;
        sent.current = secondsLeft;
        const arrived = deadlineFor(secondsLeft);
        setDeadline((current) => nextDeadline(current, arrived, rearmed));
    }, [secondsLeft, refreshedBy]);

    if (now === null) {
        return secondsLeft;
    }

    // Capped: just past a second boundary, the ticker may still hold the second before.
    return Math.min(
        secondsLeft,
        Math.max(0, Math.ceil((deadline - now) / 1000)),
    );
}

function deadlineFor(secondsLeft: number): number {
    return currentSecond() + Math.max(secondsLeft - 1, 0) * 1000;
}
