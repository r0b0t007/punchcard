import { Head, Link, router } from '@inertiajs/react';
import { ChevronLeft, Sun } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import type { CSSProperties } from 'react';
import { useTranslation } from '@/hooks/use-translation';
import { cards } from '@/routes/app';

/**
 * C10, "My QR" (CHW-28): for a phone without NFC, the customer's member QR,
 * which staff scan at the counter. Full screen, the code on its own white
 * card (scanners need the contrast, dark mode or not), a bar for the time
 * it has left, then the next one. The screen stays on while it shows
 * (Screen Wake Lock, where the browser has it).
 */
export default function MemberQr({
    qrCode,
    secondsLeft,
    windowSeconds,
    firstName,
}: {
    /** The current window's code, as an SVG data URL. */
    qrCode: string;
    secondsLeft: number;
    windowSeconds: number;
    firstName: string | null;
}) {
    const { t } = useTranslation();
    const left = useCurrentCode(qrCode, secondsLeft);

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
                        className="size-72"
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
                                    '--left': `${(Math.max(left, 0) / windowSeconds) * 100}%`,
                                } as CSSProperties
                            }
                        />
                    </div>
                    <p className="text-sm text-muted-foreground tabular-nums">
                        {t(':count second left|:count seconds left', {
                            count: Math.max(left, 0),
                        })}
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

/** The seconds the code has left, from the server's count; at zero, the next code. */
/** How long to wait before asking again for a code that did not come (offline, a slow network). */
const RETRY_MS = 3000;

/**
 * Keeps the code current, and says the seconds it has left. Counted down to
 * a deadline set when a code arrives (never ticks: a phone that slept or a
 * late timer reads the real time), then the next code is asked for, again
 * every RETRY_MS until it comes. Asked for at once when the page opens or
 * comes back into view: a locked phone, or one gone back in history, may
 * still hold an old code.
 */
function useCurrentCode(qrCode: string, secondsLeft: number): number {
    const [deadline, setDeadline] = useState(
        () => Date.now() + secondsLeft * 1000,
    );
    const [now, setNow] = useState(() => Date.now());
    const lastAsked = useRef(0);

    useEffect(() => {
        setDeadline(Date.now() + secondsLeft * 1000);
    }, [qrCode, secondsLeft]);

    const askForCode = useCallback(() => {
        lastAsked.current = Date.now();
        router.reload({ only: ['qrCode', 'secondsLeft'] });
    }, []);

    useEffect(() => {
        const timer = window.setInterval(() => setNow(Date.now()), 1000);
        const onVisible = () => {
            if (document.visibilityState === 'visible') {
                setNow(Date.now());
                askForCode();
            }
        };

        askForCode();
        document.addEventListener('visibilitychange', onVisible);

        return () => {
            window.clearInterval(timer);
            document.removeEventListener('visibilitychange', onVisible);
        };
    }, [askForCode]);

    useEffect(() => {
        if (now >= deadline && now - lastAsked.current >= RETRY_MS) {
            askForCode();
        }
    }, [now, deadline, askForCode]);

    return Math.max(0, Math.ceil((deadline - now) / 1000));
}

/** Keeps the screen on while the code shows, where the browser can; asked again when the page comes back. */
function useScreenAwake(): void {
    useEffect(() => {
        if (!('wakeLock' in navigator)) {
            return;
        }

        let lock: WakeLockSentinel | null = null;
        const request = () => {
            if (document.visibilityState !== 'visible') {
                return;
            }

            navigator.wakeLock
                .request('screen')
                .then((sentinel) => {
                    lock = sentinel;
                })
                .catch(() => {
                    // Refused (low battery, a policy): the code still shows.
                });
        };

        request();
        document.addEventListener('visibilitychange', request);

        return () => {
            document.removeEventListener('visibilitychange', request);
            void lock?.release();
        };
    }, []);
}
