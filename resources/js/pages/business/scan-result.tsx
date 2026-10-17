import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    Ban,
    Check,
    ChevronLeft,
    CirclePause,
    Gift,
    Minus,
    Plus,
    Timer,
} from 'lucide-react';
import { Fragment, useEffect, useRef, useState } from 'react';
import type { CSSProperties, ReactNode } from 'react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { useTranslation } from '@/hooks/use-translation';
import { calendarDate } from '@/lib/local-moment';
import { cn } from '@/lib/utils';
import { scan as scanner } from '@/routes/counter';
import { redeem, stamp } from '@/routes/counter/scans';

type ScanCard = {
    name: string;
    stamps: number;
    required: number;
    reward: string;
    /** The customer holds none here yet: the stamp enrolls them. */
    new: boolean;
    paused: boolean;
};

type ScanReward = { id: number; text: string };

/** How the last stamp went (CounterScanController::stamp, flash "stampResult"). */
type StampResult =
    | { state: 'done'; qty: number; rewards: number }
    | { state: 'cooldown' | 'cap'; availableAt: NextStamp | null }
    | {
          state: 'paused' | 'expired' | 'used' | 'not_yours' | 'own_code';
      };

/** When the next stamp is possible, in the site's time (LocalMoment::next): a cooldown can run a day or more. */
type NextStamp = {
    day: 'now' | 'today' | 'tomorrow' | 'later';
    date: string;
    time: string;
};

/** A reward just redeemed (flash "redeemed"): what, when, where. */
type Redeemed = { reward: string; at: string; location: string | null };

/** The quick quantities, as counter mode's (S1). */
const QUICK = [1, 2, 3, 5];

/**
 * S2, the result of a scan (CHW-30, review 3f): the customer's first name,
 * biggest on the screen so a code passed on from another phone shows; the
 * card the stamp lands on, its new stamps as saffron rings; counter mode's
 * quick quantities and an exact stepper (1 to maxQty); their rewards ready,
 * each redeemed after a confirm (S3). The scan's time runs as a quiet bar.
 * After the tap the outcome shows where the Add button was (3i: green done,
 * amber wait, red redo); what ends the scan takes the screen (3j), and a
 * redemption gets its own green moment to show the customer (3l).
 */
export default function ScanResult({
    scan,
    firstName,
    card,
    rewards,
    stamped,
    secondsLeft,
    totalSeconds,
    maxQty,
}: {
    scan: string;
    firstName: string | null;
    card: ScanCard | null;
    rewards: ScanReward[];
    stamped: boolean;
    secondsLeft: number;
    totalSeconds: number;
    maxQty: number;
}) {
    const { t } = useTranslation();
    const flash = usePage().flash as {
        stampResult?: StampResult;
        redeemed?: Redeemed;
    };
    const [result, setResult] = useState<StampResult | null>(
        flash.stampResult ?? null,
    );
    const [redeemed, setRedeemed] = useState<Redeemed | null>(
        flash.redeemed ?? null,
    );
    const [qty, setQty] = useState(1);
    const [busy, setBusy] = useState(false);
    const [confirming, setConfirming] = useState<ScanReward | null>(null);
    // One key per press: a retry after a lost answer sends it again and gets the same stamp.
    const key = useRef(newKey());
    const left = useSecondsLeft(secondsLeft);
    const name = firstName ?? t('The customer');

    useEffect(() => {
        if (flash.stampResult) {
            setResult(flash.stampResult);
        }
    }, [flash.stampResult]);

    useEffect(() => {
        if (flash.redeemed) {
            setRedeemed(flash.redeemed);
            setConfirming(null);
        }
    }, [flash.redeemed]);

    if (redeemed) {
        return (
            <RedeemedView
                redeemed={redeemed}
                name={name}
                onBack={left > 0 ? () => setRedeemed(null) : null}
            />
        );
    }

    const ending = endingState(card, result, rewards.length > 0);

    if (ending) {
        return <Ended state={ending} name={name} />;
    }

    const expired = left === 0 && !stamped;
    // A paused card takes no stamps; its rewards are still redeemed.
    const paused = card?.paused === true || result?.state === 'paused';
    const canStamp = !stamped && !expired && !busy && !paused;
    const add = () => {
        setBusy(true);
        router.post(
            stamp.url(scan),
            { qty, key: key.current },
            {
                preserveScroll: true,
                onSuccess: () => {
                    key.current = newKey();
                },
                onFinish: () => setBusy(false),
            },
        );
    };
    const confirmRedeem = (reward: ScanReward) => {
        setBusy(true);
        router.post(
            redeem.url({ scan, reward: reward.id }),
            {},
            {
                preserveScroll: true,
                onFinish: () => {
                    setBusy(false);
                    setConfirming(null);
                },
            },
        );
    };

    return (
        <>
            <Head title={name} />
            <div className="min-h-dvh bg-background p-4 sm:p-6">
                <header className="mx-auto mb-5 flex max-w-5xl items-center justify-between gap-4">
                    <Link
                        href={scanner()}
                        className="flex items-center gap-1 text-sm"
                    >
                        <ChevronLeft
                            aria-hidden="true"
                            className="size-4 rtl:rotate-180"
                        />
                        {t('Scan another')}
                    </Link>
                    <div className="flex w-52 items-center gap-2.5 text-sm text-muted-foreground">
                        <Timer aria-hidden="true" className="size-4 shrink-0" />
                        <div
                            role="progressbar"
                            aria-label={t('Time left with this scan')}
                            aria-valuemin={0}
                            aria-valuemax={totalSeconds}
                            aria-valuenow={left}
                            className="h-1.5 flex-1 overflow-hidden rounded-full bg-muted"
                        >
                            <div
                                className={cn(
                                    'h-full w-(--left) rounded-full transition-all duration-1000 ease-linear motion-reduce:transition-none',
                                    left <= 20
                                        ? 'bg-destructive'
                                        : 'bg-foreground',
                                )}
                                style={
                                    {
                                        '--left': `${(left / totalSeconds) * 100}%`,
                                    } as CSSProperties
                                }
                            />
                        </div>
                        <span dir="ltr" className="tabular-nums">
                            {Math.floor(left / 60)}:
                            {String(left % 60).padStart(2, '0')}
                        </span>
                    </div>
                </header>

                <div className="mx-auto grid max-w-5xl grid-cols-1 gap-7 md:grid-cols-2">
                    <section className="flex flex-col gap-5">
                        <div className="flex flex-col gap-1">
                            <p className="text-sm text-muted-foreground">
                                {t('Scanned just now')}
                            </p>
                            <h1
                                className="font-display text-5xl leading-none font-extrabold md:text-6xl"
                                data-test="scan-name"
                            >
                                {name}
                            </h1>
                        </div>

                        {card && (
                            <CardProgress
                                card={card}
                                adding={canStamp ? qty : 0}
                            />
                        )}

                        {rewards.map((reward) => (
                            <div
                                key={reward.id}
                                className="flex items-center gap-3 rounded-2xl border border-stamp/55 bg-stamp/15 px-4 py-3.5"
                                data-test="scan-reward"
                            >
                                <Gift
                                    aria-hidden="true"
                                    className="size-6 shrink-0"
                                />
                                <p className="min-w-0 flex-1 font-semibold">
                                    {t(':reward ready', {
                                        reward: reward.text,
                                    })}
                                </p>
                                <Button
                                    onClick={() => setConfirming(reward)}
                                    data-test="scan-redeem"
                                    disabled={busy || left === 0}
                                >
                                    {t('Redeem')}
                                </Button>
                            </div>
                        ))}
                    </section>

                    <section
                        className="flex flex-col gap-4"
                        aria-labelledby="add-stamps"
                    >
                        <h2
                            id="add-stamps"
                            className="font-display text-xl font-bold"
                        >
                            {t('Add stamps')}
                        </h2>
                        <div
                            className={cn(
                                'grid grid-cols-4 gap-2.5',
                                !canStamp && 'opacity-45',
                            )}
                            dir="ltr"
                        >
                            {QUICK.filter((quick) => quick <= maxQty).map(
                                (quick) => (
                                    <button
                                        key={quick}
                                        type="button"
                                        disabled={!canStamp}
                                        aria-pressed={qty === quick}
                                        onClick={() => setQty(quick)}
                                        className={cn(
                                            'flex h-18 items-center justify-center rounded-2xl border font-display text-3xl font-bold',
                                            qty === quick
                                                ? 'border-transparent bg-stamp text-stamp-foreground ring-3 ring-foreground ring-inset'
                                                : 'bg-card',
                                        )}
                                    >
                                        +{quick}
                                    </button>
                                ),
                            )}
                        </div>
                        <div
                            className={cn(
                                'flex items-center gap-3',
                                !canStamp && 'opacity-45',
                            )}
                        >
                            <span className="text-sm text-muted-foreground">
                                {t('Exactly')}
                            </span>
                            <div className="flex items-center gap-2" dir="ltr">
                                <Stepper
                                    label={t('One fewer')}
                                    disabled={!canStamp || qty <= 1}
                                    onClick={() =>
                                        setQty((value) =>
                                            Math.max(1, value - 1),
                                        )
                                    }
                                >
                                    <Minus
                                        aria-hidden="true"
                                        className="size-5"
                                    />
                                </Stepper>
                                <output
                                    className="min-w-9 text-center font-display text-3xl font-bold"
                                    aria-live="polite"
                                    data-test="scan-qty"
                                >
                                    {qty}
                                </output>
                                <Stepper
                                    label={t('One more')}
                                    disabled={!canStamp || qty >= maxQty}
                                    onClick={() =>
                                        setQty((value) =>
                                            Math.min(maxQty, value + 1),
                                        )
                                    }
                                >
                                    <Plus
                                        aria-hidden="true"
                                        className="size-5"
                                    />
                                </Stepper>
                            </div>
                            <span className="text-sm text-muted-foreground">
                                {t('up to :max', { max: maxQty })}
                            </span>
                        </div>
                        {card && canStamp && (
                            <p className="text-sm text-muted-foreground">
                                {card.stamps + qty >= card.required
                                    ? t('That completes the card: :reward', {
                                          reward: card.reward,
                                      })
                                    : t(
                                          ':name will be at :stamps of :required',
                                          {
                                              name,
                                              stamps: card.stamps + qty,
                                              required: card.required,
                                          },
                                      )}
                            </p>
                        )}

                        <div className="mt-auto flex flex-col gap-3 pt-2">
                            <Outcome
                                result={result}
                                stamped={stamped}
                                expired={expired}
                                paused={paused}
                                name={name}
                                card={card}
                            />
                            {canStamp && (
                                <Button
                                    onClick={add}
                                    variant="stamp"
                                    size="counter"
                                    data-test="scan-add"
                                >
                                    {t('Add :count stamp|Add :count stamps', {
                                        count: qty,
                                    })}
                                </Button>
                            )}
                        </div>
                    </section>
                </div>
            </div>

            <Dialog
                open={confirming !== null}
                onOpenChange={(open) => !open && setConfirming(null)}
            >
                <DialogContent className="text-center sm:max-w-md">
                    <div className="mx-auto flex size-24 items-center justify-center rounded-full bg-stamp text-stamp-foreground">
                        <Gift aria-hidden="true" className="size-10" />
                    </div>
                    <DialogTitle>{t('Hand over this reward')}</DialogTitle>
                    {/* The reward's name large, so staff hand over the right thing (S3, review 3k). */}
                    <p className="font-display text-3xl font-bold">
                        {confirming?.text}
                    </p>
                    <DialogDescription>
                        {t('For :name. Hand it over, then confirm.', { name })}
                    </DialogDescription>
                    <Button
                        size="lg"
                        disabled={busy}
                        onClick={() => confirming && confirmRedeem(confirming)}
                        data-test="scan-confirm-redeem"
                    >
                        {t("Confirm it's handed over")}
                    </Button>
                    <Button variant="ghost" onClick={() => setConfirming(null)}>
                        {t('Not now')}
                    </Button>
                </DialogContent>
            </Dialog>
        </>
    );
}

/** The card the stamp lands on: its stamps, the ones about to be added as saffron rings. */
function CardProgress({ card, adding }: { card: ScanCard; adding: number }) {
    const { t } = useTranslation();
    const dots = card.required <= 20;

    return (
        <div className="flex flex-col gap-4 rounded-2xl border bg-card p-5">
            <div className="flex items-center justify-between gap-2">
                <div className="flex items-center gap-2">
                    <p className="font-semibold">{card.name}</p>
                    {card.new && (
                        <span className="rounded-full bg-stamp px-2.5 py-0.5 text-xs font-medium text-stamp-foreground">
                            {t('New here')}
                        </span>
                    )}
                </div>
                <p className="font-display text-2xl font-bold" dir="ltr">
                    {card.stamps}/{card.required}
                </p>
            </div>
            {dots ? (
                <div className="grid w-fit grid-cols-5 gap-2.5">
                    {Array.from({ length: card.required }, (_, index) => (
                        <span
                            key={index}
                            aria-hidden="true"
                            className={cn(
                                'size-11 rounded-full',
                                index < card.stamps
                                    ? 'bg-stamp'
                                    : index < card.stamps + adding
                                      ? 'border-2 border-stamp bg-stamp/20'
                                      : 'border-2 border-dashed',
                            )}
                        />
                    ))}
                </div>
            ) : (
                <div className="h-2 overflow-hidden rounded-full bg-muted">
                    <div
                        className="h-full w-(--done) rounded-full bg-stamp"
                        style={
                            {
                                '--done': `${(card.stamps / card.required) * 100}%`,
                            } as CSSProperties
                        }
                    />
                </div>
            )}
            <p className="text-sm text-muted-foreground">
                {t('Reward at :count: :reward', {
                    count: card.required,
                    reward: card.reward,
                })}
            </p>
        </div>
    );
}

function Stepper({
    label,
    disabled,
    onClick,
    children,
}: {
    label: string;
    disabled: boolean;
    onClick: () => void;
    children: ReactNode;
}) {
    return (
        <button
            type="button"
            aria-label={label}
            disabled={disabled}
            onClick={onClick}
            className="flex size-12 items-center justify-center rounded-xl border bg-card disabled:opacity-50"
        >
            {children}
        </button>
    );
}

/** 3i: the outcome in place of the Add button. */
/** "Next stamp available (tomorrow / on Monday 19 October) at", as the tap's cooldown screen says it. */
function useNextStampLabel(): (next: NextStamp) => string {
    const { t, locale } = useTranslation();

    return (next) => {
        switch (next.day) {
            case 'later':
                return t('Next stamp available on :date at', {
                    date: calendarDate(locale, next.date, {
                        weekday: 'long',
                        day: 'numeric',
                        month: 'long',
                    }),
                });
            case 'tomorrow':
                return t('Next stamp available tomorrow at');
            default:
                return t('Next stamp available at');
        }
    };
}

function Outcome({
    result,
    stamped,
    expired,
    paused,
    name,
    card,
}: {
    result: StampResult | null;
    stamped: boolean;
    expired: boolean;
    paused: boolean;
    name: string;
    card: ScanCard | null;
}) {
    const { t } = useTranslation();
    const nextStampLabel = useNextStampLabel();

    if (result?.state === 'done') {
        return (
            <div
                className="flex gap-3.5 rounded-2xl bg-success px-4.5 py-4 text-background"
                role="status"
                data-test="scan-outcome"
            >
                <Check aria-hidden="true" className="size-6 shrink-0" />
                <div className="flex flex-col gap-0.5">
                    <p className="flex flex-wrap items-baseline gap-x-1.5 font-display text-2xl font-bold">
                        <bdi dir="ltr">+{result.qty}</bdi>
                        <span>{t('for :name', { name })}</span>
                    </p>
                    <p>
                        {result.rewards > 0
                            ? t('Card complete: a reward is ready.')
                            : card
                              ? t(':name is at :stamps of :required.', {
                                    name,
                                    stamps: card.stamps,
                                    required: card.required,
                                })
                              : ''}
                    </p>
                </div>
            </div>
        );
    }

    if (result?.state === 'cooldown' || result?.state === 'cap') {
        return (
            <Note tone="wait" icon={result.state === 'cap' ? Ban : Timer}>
                <p className="text-lg font-semibold">
                    {result.state === 'cap'
                        ? t("Today's limit for this card is reached")
                        : t('Stamped too recently')}
                </p>
                <p className="text-muted-foreground">
                    {result.availableAt && result.availableAt.day !== 'now' && (
                        <>
                            {nextStampLabel(result.availableAt)}{' '}
                            <bdi dir="ltr">{result.availableAt.time}</bdi>
                            {'. '}
                        </>
                    )}
                    {t('Nothing was added.')}
                </p>
            </Note>
        );
    }

    if (result?.state === 'expired' || expired) {
        return (
            <Note tone="redo" icon={Timer}>
                <p className="text-lg font-semibold">
                    {t('This scan ran out')}
                </p>
                <p className="text-muted-foreground">
                    {t('Ask :name to show their QR again.', { name })}
                </p>
                <Button asChild className="mt-1 self-start">
                    <Link href={scanner()}>{t('Scan again')}</Link>
                </Button>
            </Note>
        );
    }

    if (paused) {
        return (
            <Note tone="wait" icon={CirclePause}>
                <p className="text-lg font-semibold">
                    {t('This card is paused')}
                </p>
                <p className="text-muted-foreground">
                    {t(
                        'No stamps for now. Rewards ready can still be redeemed.',
                    )}
                </p>
            </Note>
        );
    }

    if (result?.state === 'used' || stamped) {
        return (
            <Note tone="wait" icon={Check}>
                <p className="text-lg font-semibold">
                    {t('This scan gave its stamp')}
                </p>
                <p className="text-muted-foreground">
                    {t('Scan the code again for another stamp.')}
                </p>
            </Note>
        );
    }

    return null;
}

function Note({
    tone,
    icon: Glyph,
    children,
}: {
    tone: 'wait' | 'redo';
    icon: typeof Timer;
    children: ReactNode;
}) {
    return (
        <div
            role="status"
            data-test="scan-outcome"
            className={cn(
                'flex gap-3.5 rounded-2xl border px-4.5 py-4',
                tone === 'wait'
                    ? 'border-warning/40 bg-warning/10'
                    : 'border-destructive/35 bg-destructive/8',
            )}
        >
            <Glyph
                aria-hidden="true"
                className={cn(
                    'size-6 shrink-0',
                    tone === 'wait' ? 'text-warning' : 'text-destructive',
                )}
            />
            <div className="flex flex-col gap-0.5">{children}</div>
        </div>
    );
}

type Ending = 'noCard' | 'paused' | 'own_code' | 'not_yours';

/** What leaves nothing to do on S2 (3j). */
function endingState(
    card: ScanCard | null,
    result: StampResult | null,
    hasRewards: boolean,
): Ending | null {
    // Defensive: the policy and ScanMemberQr stop these first; LockScan can still say so.
    if (result?.state === 'own_code' || result?.state === 'not_yours') {
        return result.state;
    }

    if (card === null) {
        return 'noCard';
    }

    // A paused card with rewards ready keeps S2, for redeeming them.
    return (card.paused || result?.state === 'paused') && !hasRewards
        ? 'paused'
        : null;
}

function Ended({ state, name }: { state: Ending; name: string }) {
    const { t } = useTranslation();
    const content = {
        noCard: [
            Ban,
            t('No card runs here'),
            t('Stamps start once the business runs a card.'),
        ],
        paused: [
            CirclePause,
            t('This card is paused'),
            t(
                ':name keeps their stamps. The owner can run it again from the dashboard.',
                { name },
            ),
        ],
        own_code: [
            Ban,
            t("That's your own code"),
            t("Staff can't stamp themselves. Ask a colleague to scan it."),
        ],
        not_yours: [
            Ban,
            t('This scan is not yours'),
            t('Scan the code yourself to stamp or redeem.'),
        ],
    } as const;
    const [Glyph, title, body] = content[state];

    return (
        <>
            <Head title={title} />
            <main
                className="flex min-h-dvh flex-col items-center justify-center gap-5 bg-background p-6 text-center"
                data-test="scan-ended"
            >
                <div className="flex size-24 items-center justify-center rounded-full bg-muted">
                    <Glyph aria-hidden="true" className="size-10" />
                </div>
                <h1 className="max-w-lg font-display text-3xl font-bold">
                    {title}
                </h1>
                <p className="max-w-md text-muted-foreground">{body}</p>
                <Button asChild size="lg" className="w-full max-w-sm">
                    <Link href={scanner()}>{t('Scan another')}</Link>
                </Button>
            </main>
        </>
    );
}

/** 3l: the reward handed over, big enough to turn the tablet to the customer. */
function RedeemedView({
    redeemed,
    name,
    onBack,
}: {
    redeemed: Redeemed;
    name: string;
    onBack: (() => void) | null;
}) {
    const { t } = useTranslation();

    return (
        <>
            <Head title={redeemed.reward} />
            <main
                className="flex min-h-dvh flex-col items-center justify-center gap-5 bg-background p-6 text-center"
                data-test="scan-redeemed"
            >
                <div className="flex size-24 items-center justify-center rounded-full bg-success text-background">
                    <Check aria-hidden="true" className="size-10" />
                </div>
                <h1 className="font-display text-5xl leading-none font-extrabold md:text-7xl">
                    {redeemed.reward}
                </h1>
                <p className="text-lg text-muted-foreground">
                    {/* Each part isolated: in Arabic, a Latin name, the time and a site name would otherwise run together. */}
                    {[
                        t('Redeemed for :name', { name }),
                        redeemed.at,
                        redeemed.location,
                    ]
                        .filter(Boolean)
                        .map((part, index) => (
                            <Fragment key={index}>
                                {index > 0 && ' · '}
                                <bdi>{part}</bdi>
                            </Fragment>
                        ))}
                </p>
                <div className="flex w-full max-w-sm flex-col gap-3 sm:w-auto sm:max-w-none sm:flex-row">
                    {onBack && (
                        <Button variant="outline" size="lg" onClick={onBack}>
                            {t('Back to :name', { name })}
                        </Button>
                    )}
                    <Button asChild size="lg">
                        <Link href={scanner()}>{t('Scan another')}</Link>
                    </Button>
                </div>
            </main>
        </>
    );
}

/** The scan's seconds left, counted down to a deadline set when the page arrives (never ticks). */
function useSecondsLeft(secondsLeft: number): number {
    const [deadline] = useState(() => Date.now() + secondsLeft * 1000);
    const [now, setNow] = useState(() => Date.now());

    useEffect(() => {
        const timer = window.setInterval(() => setNow(Date.now()), 1000);

        return () => window.clearInterval(timer);
    }, []);

    return Math.max(0, Math.ceil((deadline - now) / 1000));
}

function newKey(): string {
    return typeof crypto !== 'undefined' && 'randomUUID' in crypto
        ? crypto.randomUUID()
        : `${Date.now()}-${Math.random().toString(36).slice(2)}`;
}
