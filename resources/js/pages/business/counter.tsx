import { Head, Link, router, usePage, usePoll } from '@inertiajs/react';
import { ScanLine } from 'lucide-react';
import { useCallback, useEffect } from 'react';
import type { CSSProperties } from 'react';
import { RecentStamps } from '@/components/dashboard/stamp-lists';
import type { DashboardStats } from '@/components/dashboard/types';
import InputError from '@/components/input-error';
import { Button, buttonVariants } from '@/components/ui/button';
import { useTranslation } from '@/hooks/use-translation';
import { useSecond, useSecondsLeft } from '@/lib/seconds';
import { cn } from '@/lib/utils';
import { dashboard } from '@/routes';
import { arm, disarm, scan } from '@/routes/counter';
import { edit as settings } from '@/routes/profile';

type CounterStamper = {
    id: number;
    label: string | null;
    location: string | null;
    enabled: boolean;
    /** The last stamp it gave (ISO), or null. */
    lastTapAt: string | null;
    /** What its next tap gives while armed, else null. */
    armedQty: number | null;
    armedSecondsLeft: number | null;
};

/**
 * S1, counter mode (CHW-29): built for a morning rush, at arm's length on a
 * phone or a tablet. Each stamper the user may arm, its last tap, and "Next
 * tap gives" +1 +2 +3 +5 for armSeconds; armed, its panel turns saffron with
 * the quantity, the seconds left and "Cancel" (`BA Counter v2`, CHW-220);
 * then the last stamps given here, as they land. Stampers and feed refresh
 * every POLL_MS: an arming a tap took, or one made on another phone, and each
 * new stamp show within seconds.
 */
export default function Counter({
    businessName,
    stampers,
    feed,
    armSeconds,
    quantities,
}: {
    businessName: string;
    stampers: CounterStamper[];
    feed: DashboardStats['recentStamps'];
    armSeconds: number;
    quantities: number[];
}) {
    const { t } = useTranslation();
    const { nav, errors } = usePage<{ errors: Record<string, string> }>().props;

    usePoll(POLL_MS, { only: ['stampers', 'feed'] });

    return (
        <>
            <Head title={t('Counter mode')} />
            <div className="min-h-dvh bg-background p-4 sm:p-6">
                <header className="mx-auto mb-6 flex max-w-5xl items-center justify-between gap-4">
                    <div className="min-w-0">
                        <p className="text-sm text-muted-foreground">
                            {t('Counter mode')}
                        </p>
                        <h1 className="truncate font-display text-2xl font-bold">
                            {businessName}
                        </h1>
                    </div>
                    <div className="flex shrink-0 items-center gap-5">
                        {/* Owners have a dashboard; staff, their account settings (signing out). */}
                        <Link
                            href={nav.staff ? dashboard() : settings()}
                            className="text-sm font-medium underline underline-offset-4"
                        >
                            {nav.staff ? t('Dashboard') : t('Settings')}
                        </Link>
                        <ScanButton className="hidden sm:inline-flex" />
                    </div>
                </header>
                {/* S1's primary action (CHW-30, review 3a): a customer's member QR, for phones without NFC. */}
                <ScanButton className="mx-auto mb-6 flex w-full max-w-5xl sm:hidden" />

                <InputError
                    message={errors.qty}
                    className="mx-auto mb-4 max-w-5xl text-base"
                />

                {stampers.length === 0 ? (
                    <p className="mx-auto max-w-5xl text-muted-foreground">
                        {t(
                            'No stamper here yet: it appears once it is placed.',
                        )}
                    </p>
                ) : (
                    <div className="mx-auto grid max-w-5xl gap-4 md:grid-cols-2">
                        {stampers.map((stamper) => (
                            <StamperPanel
                                key={stamper.id}
                                stamper={stamper}
                                armSeconds={armSeconds}
                                quantities={quantities}
                            />
                        ))}
                    </div>
                )}

                <section
                    aria-labelledby="counter-feed"
                    className="mx-auto mt-8 max-w-5xl"
                >
                    <h2
                        id="counter-feed"
                        className="mb-3 font-display text-heading font-bold"
                    >
                        {t('Last stamps')}
                    </h2>
                    <div
                        className="rounded-2xl border bg-card px-4 text-card-foreground"
                        data-test="counter-feed"
                    >
                        <RecentStamps stamps={feed} large />
                    </div>
                </section>
            </div>
        </>
    );
}

function ScanButton({ className }: { className: string }) {
    const { t } = useTranslation();

    return (
        <Button asChild size="lg" className={className}>
            <Link href={scan()} data-test="counter-scan">
                <ScanLine aria-hidden="true" className="size-5" />
                {t('Scan a QR')}
            </Link>
        </Button>
    );
}

function StamperPanel({
    stamper,
    armSeconds,
    quantities,
}: {
    stamper: CounterStamper;
    armSeconds: number;
    quantities: number[];
}) {
    const { t } = useTranslation();
    // Stable, so the countdown's effect is not re-run by each render.
    const reloadStampers = useCallback(
        () => router.reload({ only: ['stampers'] }),
        [],
    );
    const armed =
        stamper.enabled &&
        stamper.armedQty !== null &&
        stamper.armedSecondsLeft !== null;

    return (
        <section
            aria-labelledby={`stamper-${stamper.id}`}
            // Armed is saffron (BA Counter v2, review 1g): the whole panel, seen across the counter.
            className={cn(
                // min-w-0: a grid item that shrinks to the phone, its name truncated beside Cancel.
                'flex min-w-0 flex-col gap-3.5 rounded-panel border p-5.5',
                armed
                    ? 'border-stamp bg-stamp text-stamp-foreground'
                    : 'bg-card text-card-foreground',
            )}
            data-test="counter-stamper"
        >
            <div className="flex items-start justify-between gap-3">
                <div className="flex min-w-0 flex-col gap-1">
                    <div className="flex items-center gap-2.5">
                        {armed ? null : (
                            <span
                                aria-hidden="true"
                                className={cn(
                                    'size-2.5 shrink-0 rounded-full',
                                    stamper.enabled
                                        ? 'bg-success'
                                        : 'bg-muted-foreground',
                                )}
                            />
                        )}
                        <h2
                            id={`stamper-${stamper.id}`}
                            className="truncate font-display text-xl font-bold"
                        >
                            {stamper.label ?? t('Stamper')}
                            {stamper.location ? ` · ${stamper.location}` : null}
                        </h2>
                    </div>
                    <p
                        className={cn(
                            'text-sm',
                            armed ? 'opacity-80' : 'text-muted-foreground',
                        )}
                    >
                        {stamper.enabled ? (
                            <LastTap at={stamper.lastTapAt} />
                        ) : (
                            t('This stamper is off.')
                        )}
                    </p>
                </div>
                {armed ? (
                    <Link
                        href={disarm(stamper.id)}
                        as="button"
                        preserveScroll
                        className="flex h-13 shrink-0 items-center justify-center rounded-button border border-stamp-foreground/35 px-5.5 text-lg font-semibold focus-visible:ring-3 focus-visible:ring-ring focus-visible:outline-none"
                        data-test="counter-disarm"
                    >
                        {t('Cancel')}
                    </Link>
                ) : null}
            </div>

            {armed ? (
                <ArmedCountdown
                    qty={stamper.armedQty ?? 0}
                    secondsLeft={stamper.armedSecondsLeft ?? 0}
                    total={armSeconds}
                    polled={stamper}
                    onDone={reloadStampers}
                />
            ) : stamper.enabled ? (
                <div className="flex flex-col gap-3">
                    <p className="text-md font-medium">{t('Next tap gives')}</p>
                    <div className="grid grid-cols-4 gap-2.5">
                        {quantities.map((qty) => (
                            <Link
                                key={qty}
                                href={arm(stamper.id)}
                                data={{ qty }}
                                as="button"
                                preserveScroll
                                // cn() merges the base text-sm away; the quantities read at 30px (BA Counter v2, .qb).
                                className={cn(
                                    buttonVariants({ size: 'counter' }),
                                    'text-3xl',
                                )}
                                aria-label={t('Next tap gives :count', {
                                    count: `+${qty}`,
                                })}
                                data-test={`counter-arm-${qty}`}
                            >
                                <bdi dir="ltr">+{qty}</bdi>
                            </Link>
                        ))}
                    </div>
                </div>
            ) : null}
        </section>
    );
}

/**
 * An armed stamper: the quantity big enough to read across the counter
 * ("+2" always left to right), the seconds left and a bar emptying over the
 * arming time; then the stampers again, the arming gone.
 */
function ArmedCountdown({
    qty,
    secondsLeft,
    total,
    polled,
    onDone,
}: {
    qty: number;
    secondsLeft: number;
    total: number;
    /** The stamper as the last poll brought it: each poll may carry a new arming. */
    polled: CounterStamper;
    onDone: () => void;
}) {
    const { t } = useTranslation();
    const left = useSecondsLeft(secondsLeft, polled);
    // Runtime values enter the UI only as CSS variables (design-tokens skill).
    const bar = {
        '--left': `${(left / Math.max(total, 1)) * 100}%`,
    } as CSSProperties;

    useEffect(() => {
        if (left === 0) {
            onDone();
        }
    }, [left, onDone]);

    return (
        <>
            <div className="flex items-end gap-4.5">
                <bdi
                    dir="ltr"
                    className="font-display text-giant font-extrabold tabular-nums"
                >
                    +{qty}
                </bdi>
                <div className="flex flex-col gap-0.5 pb-1.5">
                    <p className="text-lg font-bold">{t('Next tap gives')}</p>
                    <p className="text-md tabular-nums">
                        {t(':count second left|:count seconds left', {
                            count: left,
                        })}
                    </p>
                </div>
            </div>
            <div
                aria-hidden="true"
                className="h-2 overflow-hidden rounded-full bg-stamp-foreground/15"
            >
                <div
                    style={bar}
                    className="h-full w-(--left) rounded-full bg-stamp-foreground transition-all duration-1000 ease-linear motion-reduce:transition-none"
                />
            </div>
        </>
    );
}

/** How often the stampers refresh: an arming a tap took shows within seconds. */

const POLL_MS = 3000;

/**
 * "Last tap 12 seconds ago", in the page's language, "No tap yet" before the
 * first; it keeps counting each second, this line alone.
 */
function LastTap({ at }: { at: string | null }) {
    const { t, locale } = useTranslation();
    // Each whole second, one ticker for every stamper (lib/seconds).
    const now = useSecond();

    if (at === null) {
        return t('No tap yet');
    }

    // Rendered on the server: the phone's clock fills it in.
    if (now === null) {
        return null;
    }

    const seconds = Math.max(0, Math.round((now - Date.parse(at)) / 1000));
    const format = new Intl.RelativeTimeFormat(locale, { numeric: 'auto' });
    const ago =
        seconds < 60
            ? format.format(-seconds, 'second')
            : seconds < 3600
              ? format.format(-Math.floor(seconds / 60), 'minute')
              : seconds < 86400
                ? format.format(-Math.floor(seconds / 3600), 'hour')
                : format.format(-Math.floor(seconds / 86400), 'day');

    return t('Last tap :ago', { ago });
}
