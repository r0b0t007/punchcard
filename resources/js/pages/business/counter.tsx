import { Head, Link, router, usePage, usePoll } from '@inertiajs/react';
import { useCallback, useSyncExternalStore } from 'react';
import InputError from '@/components/input-error';
import CountdownRing from '@/components/rewards/countdown-ring';
import { Button, buttonVariants } from '@/components/ui/button';
import { useTranslation } from '@/hooks/use-translation';
import { cn } from '@/lib/utils';
import { dashboard } from '@/routes';
import { arm, disarm } from '@/routes/counter';
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
 * tap gives" +1 +2 +3 +5 for armSeconds, with a countdown and "Cancel".
 * The stampers refresh every POLL_MS: an arming a tap took, or one made on
 * another phone, shows within seconds.
 */
export default function Counter({
    businessName,
    stampers,
    armSeconds,
    quantities,
}: {
    businessName: string;
    stampers: CounterStamper[];
    armSeconds: number;
    quantities: number[];
}) {
    const { t } = useTranslation();
    const { nav, errors } = usePage<{ errors: Record<string, string> }>().props;

    usePoll(POLL_MS, { only: ['stampers'] });

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
                    {/* Owners have a dashboard; staff, their account settings (signing out). */}
                    <Link
                        href={nav.staff ? dashboard() : settings()}
                        className="shrink-0 text-sm font-medium underline underline-offset-4"
                    >
                        {nav.staff ? t('Dashboard') : t('Settings')}
                    </Link>
                </header>

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
            </div>
        </>
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
    const { t, tn } = useTranslation();
    // Stable, so the ring's timer is not reset by each render (as on the redeem screen).
    const reloadStampers = useCallback(
        () => router.reload({ only: ['stampers'] }),
        [],
    );
    const armed =
        stamper.armedQty !== null && stamper.armedSecondsLeft !== null;

    return (
        <section
            aria-labelledby={`stamper-${stamper.id}`}
            className="flex flex-col gap-5 rounded-2xl border bg-card p-5 text-card-foreground"
            data-test="counter-stamper"
        >
            <div className="flex items-start gap-3">
                <span
                    aria-hidden="true"
                    className={cn(
                        'mt-2 size-3 shrink-0 rounded-full',
                        stamper.enabled ? 'bg-success' : 'bg-muted-foreground',
                    )}
                />
                <div className="min-w-0">
                    <h2
                        id={`stamper-${stamper.id}`}
                        className="truncate text-xl font-semibold"
                    >
                        {stamper.label ?? t('Stamper')}
                        {stamper.location ? ` · ${stamper.location}` : null}
                    </h2>
                    <p className="text-muted-foreground">
                        {stamper.enabled ? (
                            <LastTap at={stamper.lastTapAt} />
                        ) : (
                            t('This stamper is off.')
                        )}
                    </p>
                </div>
            </div>

            {stamper.enabled && armed ? (
                <div className="flex items-center gap-5">
                    <CountdownRing
                        compact
                        seconds={stamper.armedSecondsLeft ?? 0}
                        total={armSeconds}
                        onDone={reloadStampers}
                    />
                    <div className="flex flex-1 flex-col gap-3">
                        <p className="text-2xl font-bold">
                            {/* "+5" reads left to right, also in an Arabic sentence. */}
                            {tn('Next tap gives :count', {
                                count: <bdi dir="ltr">+{stamper.armedQty}</bdi>,
                            })}
                        </p>
                        <Button asChild variant="outline" size="counter">
                            <Link
                                href={disarm(stamper.id)}
                                as="button"
                                preserveScroll
                                data-test="counter-disarm"
                            >
                                {t('Cancel')}
                            </Link>
                        </Button>
                    </div>
                </div>
            ) : stamper.enabled ? (
                <div className="flex flex-col gap-3">
                    <p className="font-medium">{t('Next tap gives')}</p>
                    <div className="grid grid-cols-4 gap-3">
                        {quantities.map((qty) => (
                            <Link
                                key={qty}
                                href={arm(stamper.id)}
                                data={{ qty }}
                                as="button"
                                preserveScroll
                                // cn() merges the base text-sm away, so the counter size's text-2xl shows.
                                className={cn(
                                    buttonVariants({ size: 'counter' }),
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
 * One ticker for the whole page, each second, whatever the number of
 * stampers: every "last tap" line reads it (useSyncExternalStore).
 */
const secondListeners = new Set<() => void>();
let secondTimer: number | undefined;
let second = Date.now();

function subscribeToSeconds(listener: () => void): () => void {
    secondListeners.add(listener);
    secondTimer ??= window.setInterval(() => {
        second = Date.now();
        secondListeners.forEach((notify) => notify());
    }, 1000);

    return () => {
        secondListeners.delete(listener);

        if (secondListeners.size === 0) {
            window.clearInterval(secondTimer);
            secondTimer = undefined;
        }
    };
}

function readSecond(): number {
    return second;
}

/** How often the stampers refresh: an arming a tap took shows within seconds. */

const POLL_MS = 3000;

/**
 * "Last tap 12 seconds ago", in the page's language, "No tap yet" before the
 * first; it keeps counting each second, this line alone.
 */
function LastTap({ at }: { at: string | null }) {
    const { t, locale } = useTranslation();
    const now = useSyncExternalStore(
        subscribeToSeconds,
        readSecond,
        readSecond,
    );

    if (at === null) {
        return t('No tap yet');
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
