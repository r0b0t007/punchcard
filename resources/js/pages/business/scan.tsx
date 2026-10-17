import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    Ban,
    Camera,
    ChevronLeft,
    Delete,
    Flashlight,
    KeyRound,
    MapPin,
    Repeat,
    Timer,
} from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import type { FormEvent } from 'react';
import type QrScanner from 'qr-scanner';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/hooks/use-translation';
import { cn } from '@/lib/utils';
import { show as counter } from '@/routes/counter';
import { store } from '@/routes/counter/scans';

type ScanLocation = { id: number; name: string };

/** Why a code ended in a full-screen refusal (CounterScanController::refused). */
type ScanRefusal =
    | 'own_code'
    | 'replayed'
    | 'too_many_attempts'
    | 'too_many_wrong_codes'
    | 'expired';

/** How long the camera ignores the code it last sent (a bad QR held up is never sent in a loop). */
const REREAD_MS = 10_000;

/** A member QR's token starts with its version; anything else read by the camera is not ours. */
const TOKEN_PREFIX = 'PC1.';

/**
 * S2a, the scanner (CHW-30, review 3d on a tablet, 3c on a phone): the
 * camera starts with the screen and reads a member QR by itself, no button;
 * typing the short code is always there, a keypad on the counter tablet, a
 * field on a phone. An owner with several sites picks where they scan. A
 * code that doesn't count is said on the field; one that leaves nothing to
 * retry (the staff member's own, already scanned, too many wrong codes)
 * takes the screen with one way out.
 */
export default function Scan({ locations }: { locations: ScanLocation[] }) {
    const { t } = useTranslation();
    const page = usePage<{ errors: Record<string, string> }>();
    const errors = page.props.errors;
    const flashed = (page.flash as { scanRefusal?: ScanRefusal }).scanRefusal;
    const [location, setLocation] = useState(locations[0]?.id ?? 0);
    const [digits, setDigits] = useState('');
    const [sending, setSending] = useState(false);
    const [refusal, setRefusal] = useState<ScanRefusal | null>(flashed ?? null);

    useEffect(() => {
        setRefusal(flashed ?? null);
    }, [flashed]);

    const send = useCallback(
        (code: string) => {
            if (sending || location === 0) {
                return;
            }

            setSending(true);
            router.post(
                store.url(),
                { code, location },
                {
                    preserveState: true,
                    preserveScroll: true,
                    onFinish: () => setSending(false),
                },
            );
        },
        [sending, location],
    );

    if (refusal) {
        return <Refused reason={refusal} onAgain={() => setRefusal(null)} />;
    }

    const submit = (event: FormEvent) => {
        event.preventDefault();
        send(digits);
    };

    return (
        <>
            <Head title={t('Scan a QR')} />
            <div className="min-h-dvh bg-background p-4 sm:p-6">
                <header className="mx-auto mb-4 flex max-w-5xl items-center justify-between gap-4">
                    <Link
                        href={counter()}
                        className="flex items-center gap-1 text-sm"
                    >
                        <ChevronLeft
                            aria-hidden="true"
                            className="size-4 rtl:rotate-180"
                        />
                        {t('Counter mode')}
                    </Link>
                    {locations.length > 1 && (
                        <label className="flex h-10 items-center gap-2 rounded-full border bg-card px-3 text-sm font-medium">
                            <MapPin aria-hidden="true" className="size-4" />
                            <span className="sr-only">{t('Site')}</span>
                            <select
                                value={location}
                                onChange={(event) =>
                                    setLocation(Number(event.target.value))
                                }
                                className="appearance-none bg-transparent outline-none"
                            >
                                {locations.map((site) => (
                                    <option key={site.id} value={site.id}>
                                        {site.name}
                                    </option>
                                ))}
                            </select>
                        </label>
                    )}
                </header>

                {locations.length === 0 ? (
                    <p className="mx-auto max-w-5xl text-muted-foreground">
                        {t('There is no open site to scan at.')}
                    </p>
                ) : (
                    <div className="mx-auto grid max-w-5xl grid-cols-1 gap-6 md:grid-cols-2">
                        <section className="flex flex-col gap-3">
                            <h1 className="font-display text-2xl font-bold md:text-3xl">
                                {t("Scan the customer's QR")}
                            </h1>
                            <CameraView onCode={send} paused={sending} />
                        </section>

                        <form
                            onSubmit={submit}
                            className="flex flex-col gap-3"
                            noValidate
                        >
                            <label
                                htmlFor="code"
                                className="font-display text-xl font-bold md:pt-1"
                            >
                                {t('…or type the code under it')}
                            </label>
                            <div className="flex gap-2">
                                <input
                                    id="code"
                                    name="code"
                                    dir="ltr"
                                    inputMode="numeric"
                                    autoComplete="off"
                                    placeholder="1042 381 925"
                                    value={grouped(digits)}
                                    onChange={(event) =>
                                        setDigits(
                                            event.target.value.replace(
                                                /\D+/g,
                                                '',
                                            ),
                                        )
                                    }
                                    aria-invalid={Boolean(errors.code)}
                                    aria-describedby="code-error"
                                    data-test="scan-code"
                                    className="h-14 min-w-0 flex-1 rounded-xl border-2 border-foreground bg-card px-4 text-center font-display text-3xl font-bold tracking-wide tabular-nums placeholder:text-muted-foreground/50 md:h-18 md:text-4xl"
                                />
                                <Button
                                    type="submit"
                                    size="lg"
                                    className="md:hidden"
                                    disabled={digits.length < 7 || sending}
                                >
                                    {t('Find')}
                                </Button>
                            </div>
                            <InputError
                                id="code-error"
                                message={errors.code}
                                className="text-base"
                            />
                            <Keypad
                                onDigit={(digit) =>
                                    setDigits((typed) =>
                                        typed.length < 24
                                            ? typed + digit
                                            : typed,
                                    )
                                }
                                onDelete={() =>
                                    setDigits((typed) => typed.slice(0, -1))
                                }
                                ready={digits.length >= 7 && !sending}
                            />
                            <p className="hidden text-sm text-muted-foreground md:block">
                                {t(
                                    'The member number, then six digits, as shown under the QR.',
                                )}
                            </p>
                        </form>
                    </div>
                )}
            </div>
        </>
    );
}

/** The digits as the customer's screen shows them: the member number, then the six digits in threes. */
function grouped(digits: string): string {
    if (digits.length <= 6) {
        return digits;
    }

    const code = digits.slice(-6);

    return `${digits.slice(0, -6)} · ${code.slice(0, 3)} ${code.slice(3)}`;
}

/** The counter tablet's keypad (3d): big enough for a thumb; Find lights up once a code can be complete. */
function Keypad({
    onDigit,
    onDelete,
    ready,
}: {
    onDigit: (digit: string) => void;
    onDelete: () => void;
    ready: boolean;
}) {
    const { t } = useTranslation();
    const key =
        'flex h-17 items-center justify-center rounded-2xl border bg-card font-display text-3xl font-bold active:bg-muted';

    return (
        <div className="hidden grid-cols-3 gap-2.5 md:grid" dir="ltr">
            {['1', '2', '3', '4', '5', '6', '7', '8', '9'].map((digit) => (
                <button
                    key={digit}
                    type="button"
                    className={key}
                    onClick={() => onDigit(digit)}
                >
                    {digit}
                </button>
            ))}
            <button
                type="button"
                className={key}
                onClick={onDelete}
                aria-label={t('Delete')}
            >
                <Delete aria-hidden="true" className="size-6" />
            </button>
            <button type="button" className={key} onClick={() => onDigit('0')}>
                0
            </button>
            <button
                type="submit"
                disabled={!ready}
                className={cn(
                    key,
                    'border-primary bg-primary font-sans text-lg font-medium text-primary-foreground active:bg-primary/90 disabled:opacity-50',
                )}
            >
                {t('Find')}
            </button>
        </div>
    );
}

/**
 * The camera view: reads a member QR by itself (qr-scanner: the browser's
 * own BarcodeDetector where it has one, a small worker elsewhere, as on iOS
 * Safari). Asked for only while this screen shows. The back camera on a
 * phone; the front one on a wider screen, so a customer holds their phone
 * up to the counter tablet; staff can switch. Anything but a member QR is
 * ignored; a code read is sent once until the answer comes.
 */
function CameraView({
    onCode,
    paused,
}: {
    onCode: (code: string) => void;
    paused: boolean;
}) {
    const { t } = useTranslation();
    const video = useRef<HTMLVideoElement>(null);
    const scanner = useRef<QrScanner | null>(null);
    const latest = useRef(onCode);
    const lastRead = useRef<{ code: string; at: number } | null>(null);
    const pausedRef = useRef(paused);
    const [facing, setFacing] = useState<'environment' | 'user'>(() =>
        typeof window !== 'undefined' && window.innerWidth >= 768
            ? 'user'
            : 'environment',
    );
    const [state, setState] = useState<'starting' | 'on' | 'off'>('starting');
    const [hasFlash, setHasFlash] = useState(false);

    latest.current = onCode;
    pausedRef.current = paused;

    useEffect(() => {
        let stopped = false;
        let instance: QrScanner | null = null;

        void import('qr-scanner').then(({ default: Scanner }) => {
            if (stopped || !video.current) {
                return;
            }

            instance = new Scanner(
                video.current,
                (result) => {
                    const at = Date.now();
                    const last = lastRead.current;

                    // The same code again, read while it is still in front of the camera: it had its answer.
                    if (
                        pausedRef.current ||
                        !result.data.startsWith(TOKEN_PREFIX) ||
                        (last?.code === result.data && at - last.at < REREAD_MS)
                    ) {
                        return;
                    }

                    lastRead.current = { code: result.data, at };
                    latest.current(result.data);
                },
                {
                    returnDetailedScanResult: true,
                    preferredCamera: facing,
                    maxScansPerSecond: 8,
                },
            );
            scanner.current = instance;
            instance
                .start()
                .then(async () => {
                    if (stopped) {
                        return;
                    }

                    setState('on');
                    setHasFlash(await instance!.hasFlash());
                })
                .catch(() => {
                    if (!stopped) {
                        setState('off');
                    }
                });
        });

        return () => {
            stopped = true;
            instance?.destroy();
            scanner.current = null;
        };
    }, [facing]);

    return (
        <div className="relative flex aspect-square items-center justify-center overflow-hidden rounded-3xl bg-viewfinder text-viewfinder-foreground md:aspect-auto md:min-h-104">
            <video
                ref={video}
                muted
                playsInline
                className={cn(
                    'absolute inset-0 size-full object-cover',
                    state !== 'on' && 'invisible',
                )}
                data-test="scan-camera"
            />
            {(['a', 'b', 'c', 'd'] as const).map((corner) => (
                <span
                    key={corner}
                    aria-hidden="true"
                    className={cn(
                        'absolute size-11 border-4 border-stamp',
                        corner === 'a' &&
                            'start-1/5 top-1/5 rounded-ss-xl border-e-0 border-b-0',
                        corner === 'b' &&
                            'end-1/5 top-1/5 rounded-se-xl border-s-0 border-b-0',
                        corner === 'c' &&
                            'start-1/5 bottom-1/5 rounded-es-xl border-e-0 border-t-0',
                        corner === 'd' &&
                            'end-1/5 bottom-1/5 rounded-ee-xl border-s-0 border-t-0',
                    )}
                />
            ))}
            <div className="absolute inset-x-4 top-4 flex justify-between gap-2">
                <button
                    type="button"
                    onClick={() =>
                        setFacing((side) =>
                            side === 'user' ? 'environment' : 'user',
                        )
                    }
                    className="flex h-10 items-center gap-2 rounded-full bg-viewfinder/70 px-3.5 text-sm font-medium backdrop-blur"
                >
                    <Camera aria-hidden="true" className="size-4" />
                    {facing === 'user' ? t('Front camera') : t('Back camera')}
                </button>
                {hasFlash && (
                    <button
                        type="button"
                        onClick={() => void scanner.current?.toggleFlash()}
                        className="flex h-10 items-center gap-2 rounded-full bg-viewfinder/70 px-3.5 text-sm font-medium backdrop-blur"
                    >
                        <Flashlight aria-hidden="true" className="size-4" />
                        {t('Light')}
                    </button>
                )}
            </div>
            <p
                className="absolute inset-x-4 bottom-4 mx-auto w-fit rounded-full bg-viewfinder/70 px-3.5 py-1.5 text-center text-sm font-medium backdrop-blur"
                aria-live="polite"
            >
                {state === 'off'
                    ? t("The camera isn't available here: type the code.")
                    : facing === 'user'
                      ? t('The customer holds their phone up to the screen')
                      : t('Hold the code inside the frame')}
            </p>
        </div>
    );
}

/** 3j: a refusal that leaves nothing to retry with this code, with its one way out. */
function Refused({
    reason,
    onAgain,
}: {
    reason: ScanRefusal;
    onAgain: () => void;
}) {
    const { t } = useTranslation();
    const content = {
        own_code: {
            icon: Ban,
            title: t("That's your own code"),
            body: t(
                "Staff can't stamp themselves. Ask a colleague to scan it.",
            ),
            action: t('Scan another'),
            bad: false,
        },
        replayed: {
            icon: Repeat,
            title: t('This code was already scanned'),
            body: t('Ask for the next one: it changes every 30 seconds.'),
            action: t('Scan another'),
            bad: false,
        },
        too_many_attempts: {
            icon: KeyRound,
            title: t('Too many wrong codes for this customer'),
            body: t(
                "Typing this customer's code is paused here for a while. Their QR still works.",
            ),
            action: t('Scan the QR'),
            bad: true,
        },
        expired: {
            icon: Timer,
            title: t('This scan ran out'),
            body: t('Scan the code again: a scan lasts two minutes.'),
            action: t('Scan another'),
            bad: false,
        },
        too_many_wrong_codes: {
            icon: KeyRound,
            title: t('Too many wrong codes'),
            body: t(
                'Typing codes is paused on your account for a while. Scanning the QR still works.',
            ),
            action: t('Scan the QR'),
            bad: true,
        },
    }[reason];
    const Glyph = content.icon;

    return (
        <>
            <Head title={content.title} />
            <main
                className="flex min-h-dvh flex-col items-center justify-center gap-5 bg-background p-6 text-center"
                data-test="scan-refused"
            >
                <div
                    className={cn(
                        'flex size-24 items-center justify-center rounded-full',
                        content.bad
                            ? 'bg-destructive/10 text-destructive'
                            : 'bg-muted',
                    )}
                >
                    <Glyph aria-hidden="true" className="size-10" />
                </div>
                <h1 className="max-w-lg font-display text-3xl font-bold">
                    {content.title}
                </h1>
                <p className="max-w-md text-muted-foreground">{content.body}</p>
                {content.action ? (
                    <Button
                        size="lg"
                        className="w-full max-w-sm"
                        onClick={onAgain}
                    >
                        {content.action}
                    </Button>
                ) : (
                    <Button asChild size="lg" className="w-full max-w-sm">
                        <Link href={counter()}>
                            {t('Back to counter mode')}
                        </Link>
                    </Button>
                )}
            </main>
        </>
    );
}
