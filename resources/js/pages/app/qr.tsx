import { Head, Link, router } from '@inertiajs/react';
import { ChevronLeft, Sun } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import type { CSSProperties } from 'react';
import { useTranslation } from '@/hooks/use-translation';
import { cn } from '@/lib/utils';
import { cards } from '@/routes/app';

/**
 * C10, "My QR" (CHW-28): for a phone without NFC, the customer's member QR,
 * which staff scan at the counter. Full screen, the code on its own white
 * card (scanners need the contrast, dark mode or not), a bar for the time
 * it has left, then the next one. A code past its time is dimmed until the
 * next arrives, so nobody scans one that no longer counts. The screen stays
 * on while it shows (Screen Wake Lock, where the browser has it).
 */
export default function MemberQr({
    qrCode,
    secondsLeft,
    windowSeconds,
    issuedAt,
    firstName,
}: {
    /** The current window's code, as an SVG data URL. */
    qrCode: string;
    secondsLeft: number;
    windowSeconds: number;
    /** When the server issued it (ms): tells a code restored from history from a new one. */
    issuedAt: number;
    firstName: string | null;
}) {
    const { t } = useTranslation();
    const left = useCurrentCode(secondsLeft, issuedAt);
    const due = left === 0;

    useScreenAwake();

    return (
        <>
            <Head title={t('My QR')} />
            <main className="mx-auto flex min-h-dvh max-w-md flex-col items-center gap-6 px-4 py-6 text-center">
                <Link
                    href={cards()}
                    className="flex items-center gap-1 self-start text-sm text-muted-foreground"
                >
                    <ChevronLeft
                        aria-hidden="true"
                        className="size-4 rtl:rotate-180"
                    />
                    {t('My cards')}
                </Link>

                <div className="flex flex-col gap-1">
                    <h1 className="font-display text-2xl font-bold">
                        {firstName
                            ? t(':name, show this to staff', {
                                  name: firstName,
                              })
                            : t('Show this to staff')}
                    </h1>
                    <p className="text-muted-foreground">
                        {t('Use this if your phone has no NFC.')}
                    </p>
                </div>

                <div className="rounded-2xl bg-white p-3 shadow-sm">
                    <img
                        src={qrCode}
                        alt={t('Your member QR code')}
                        className={cn(
                            'size-72 transition-opacity',
                            due && 'opacity-20',
                        )}
                        data-test="member-qr"
                    />
                </div>

                <div className="flex w-full max-w-72 flex-col gap-2">
                    <div
                        role="progressbar"
                        aria-label={t('New code in')}
                        aria-valuemin={0}
                        aria-valuemax={windowSeconds}
                        aria-valuenow={left}
                        className="h-2 w-full overflow-hidden rounded-full bg-muted"
                    >
                        <div
                            className="h-full w-(--left) rounded-full bg-primary transition-all duration-1000 ease-linear motion-reduce:transition-none"
                            style={
                                {
                                    '--left': `${(left / windowSeconds) * 100}%`,
                                } as CSSProperties
                            }
                        />
                    </div>
                    <p className="text-sm text-muted-foreground tabular-nums">
                        {due
                            ? t('Getting a new code…')
                            : t(':count second left|:count seconds left', {
                                  count: left,
                              })}
                    </p>
                    {/* Announced when the code runs out, never each second. */}
                    <p className="sr-only" aria-live="polite">
                        {due ? t('Getting a new code…') : ''}
                    </p>
                </div>

                <p className="flex items-center gap-2 text-sm text-muted-foreground">
                    <Sun aria-hidden="true" className="size-4 shrink-0" />
                    {t('Turn your screen brightness up so it scans easily.')}
                </p>
            </main>
        </>
    );
}

/** How long to wait before asking again for a code that did not come (offline, a slow network). */
const RETRY_MS = 3000;

/**
 * When the newest code this page has shown was issued, across visits in the
 * app: props restored from history (Back) hold that code or an older one,
 * whatever the phone's clock says.
 */
let newestShown = 0;

/**
 * Keeps the code current, and says the seconds it has left. Counted down to
 * a deadline set when a code arrives (never ticks: a phone that slept or a
 * late timer reads the real time), then the next code is asked for, one
 * request at a time (a slow answer is never cancelled by a retry), again
 * RETRY_MS after one that brought nothing, and only while the page shows
 * (it asks at once when it comes back). A code shown before, restored from
 * history, is past its time from the start; a code the server has just
 * sent counts down from exactly the seconds it has left: the clock and the
 * deadline are set from one reading, so neither rounds up a second
 * (CHW-222). The code taken on is kept per mount, so strict mode running
 * the effect twice in development never mistakes it for a restored one.
 */
function useCurrentCode(secondsLeft: number, issuedAt: number): number {
    const [now, setNow] = useState(() => Date.now());
    const [deadline, setDeadline] = useState(() =>
        issuedAt <= newestShown ? now : now + secondsLeft * 1000,
    );
    const asking = useRef(false);
    const lastAnswer = useRef(0);
    const taken = useRef<number | null>(null);

    const askForCode = useCallback(() => {
        if (asking.current) {
            return;
        }

        asking.current = true;
        router.reload({
            only: ['qrCode', 'secondsLeft', 'issuedAt'],
            onFinish: () => {
                asking.current = false;
                lastAnswer.current = Date.now();
            },
        });
    }, []);

    useEffect(() => {
        if (taken.current === issuedAt) {
            return;
        }

        const at = Date.now();
        setNow(at);

        if (issuedAt <= newestShown) {
            setDeadline(at);
            askForCode();

            return;
        }

        taken.current = issuedAt;
        newestShown = issuedAt;
        setDeadline(at + secondsLeft * 1000);
    }, [secondsLeft, issuedAt, askForCode]);

    useEffect(() => {
        const timer = window.setInterval(() => setNow(Date.now()), 1000);
        const onVisible = () => {
            if (document.visibilityState === 'visible') {
                setNow(Date.now());
                askForCode();
            }
        };

        document.addEventListener('visibilitychange', onVisible);

        return () => {
            window.clearInterval(timer);
            document.removeEventListener('visibilitychange', onVisible);
        };
    }, [askForCode]);

    useEffect(() => {
        if (
            now >= deadline &&
            now - lastAnswer.current >= RETRY_MS &&
            document.visibilityState === 'visible'
        ) {
            askForCode();
        }
    }, [now, deadline, askForCode]);

    return Math.max(0, Math.ceil((deadline - now) / 1000));
}

/**
 * Keeps the screen on while the code shows, where the browser can; asked
 * again when the page comes back. A lock granted after the page was left is
 * released at once, so the screen never stays on elsewhere in the app.
 */
function useScreenAwake(): void {
    useEffect(() => {
        if (!('wakeLock' in navigator)) {
            return;
        }

        let left = false;
        let lock: WakeLockSentinel | null = null;
        const request = () => {
            if (document.visibilityState !== 'visible') {
                return;
            }

            navigator.wakeLock
                .request('screen')
                .then((sentinel) => {
                    if (left) {
                        void sentinel.release();

                        return;
                    }

                    lock = sentinel;
                })
                .catch(() => {
                    // Refused (low battery, a policy): the code still shows.
                });
        };

        request();
        document.addEventListener('visibilitychange', request);

        return () => {
            left = true;
            document.removeEventListener('visibilitychange', request);
            void lock?.release();
        };
    }, []);
}
