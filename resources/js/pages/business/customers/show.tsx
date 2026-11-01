import { Head, Link } from '@inertiajs/react';
import type { CSSProperties, ReactNode } from 'react';
import { ChevronLeft, Gift, Hand, Stamp, Undo2 } from 'lucide-react';
import { Button } from '@/components/ui/button';
import StatusChip from '@/components/customers/status-chip';
import { useTranslation } from '@/hooks/use-translation';
import type { TranslateFn, TranslateNodesFn } from '@/hooks/use-translation';
import { momentLabel } from '@/lib/local-moment';
import type { LocalMoment } from '@/lib/local-moment';
import { initials, lastVisit } from '@/lib/customers';
import type { CustomerStatus } from '@/lib/customers';
import { cn } from '@/lib/utils';
import { index, show } from '@/routes/customers';

type Customer = {
    id: number;
    /** First name and initial: never more. */
    name: string;
    status: CustomerStatus;
    visits: number;
    /** Days between their visits on average; null before a second. */
    usualGapDays: number | null;
    lastVisitDays: number;
    rewardsRedeemed: number;
};

type CardProgress = {
    id: number;
    name: string;
    rewardText: string;
    stamps: number;
    /** Stamps to the next reward; null once a progressive card's tiers are all earned. */
    toReward: number | null;
};

type HistoryEvent = {
    key: string;
    kind: 'stamp' | 'redeemed';
    /** A stamp's source: nfc, qr, manual, correction, bonus, birthday or referral. */
    source: string | null;
    qty: number;
    /** A stamp's card, or the reward redeemed. */
    card: string | null;
    location: string | null;
    /** The staff member, by first name and initial: who scanned, stamped by hand, corrected or redeemed. */
    by: string | null;
    reason: string | null;
    byScan: boolean;
    at: LocalMoment;
};

/**
 * One customer (CHW-44, B5, design 15c): their status, what they usually
 * do, their progress on each card, and what happened at this business,
 * newest first, more on asking. The owner's, never staff's.
 */
export default function CustomerPage({
    customer,
    cards,
    history,
    shown,
    more,
}: {
    customer: Customer;
    cards: CardProgress[];
    history: HistoryEvent[];
    shown: number;
    more: boolean;
}) {
    const { t } = useTranslation();

    return (
        <>
            <Head title={customer.name} />
            <div className="flex max-w-5xl flex-col gap-5 p-4">
                <Link
                    href={index()}
                    className="inline-flex w-fit items-center gap-1.5 text-sm text-muted-foreground"
                >
                    <ChevronLeft
                        aria-hidden="true"
                        className="size-4 rtl:rotate-180"
                    />
                    {t('Customers')}
                </Link>

                <div className="flex items-center gap-3">
                    <span
                        aria-hidden="true"
                        className="flex size-12 shrink-0 items-center justify-center rounded-full bg-muted text-lg font-semibold"
                    >
                        {initials(customer.name)}
                    </span>
                    <div className="flex flex-col gap-1">
                        <h1 className="font-display text-title leading-none font-bold">
                            <bdi>{customer.name}</bdi>
                        </h1>
                        <StatusChip status={customer.status} />
                    </div>
                </div>

                <div className="grid items-start gap-5 md:grid-cols-3">
                    <section
                        aria-labelledby="history"
                        className="flex flex-col gap-1 rounded-card border bg-card px-4 py-3.5 md:col-span-2"
                    >
                        <h2 id="history" className="font-semibold">
                            {t('At this business, newest first')}
                        </h2>
                        {history.length === 0 ? (
                            <p className="py-3 text-sm text-muted-foreground">
                                {t('Nothing here yet.')}
                            </p>
                        ) : (
                            <ul className="divide-y">
                                {history.map((event) => (
                                    <HistoryItem
                                        key={event.key}
                                        event={event}
                                    />
                                ))}
                            </ul>
                        )}
                        {more ? (
                            <Button
                                variant="outline"
                                size="sm"
                                className="self-center"
                                asChild
                            >
                                <Link
                                    href={show(customer.id, {
                                        query: { shown: shown + 20 },
                                    })}
                                    only={['history', 'shown', 'more']}
                                    preserveScroll
                                    preserveState
                                    data-test="customer-history-more"
                                >
                                    {t('Show older')}
                                </Link>
                            </Button>
                        ) : null}
                    </section>

                    <div className="flex flex-col gap-5">
                        <dl className="grid grid-cols-2 gap-x-4 gap-y-3 rounded-card border bg-card px-4 py-3.5">
                            <Fact label={t('Visits')} value={customer.visits} />
                            <Fact
                                label={t('Usually')}
                                value={
                                    customer.usualGapDays === null
                                        ? '—'
                                        : t(
                                              'every :count day|every :count days',
                                              {
                                                  count: customer.usualGapDays,
                                              },
                                          )
                                }
                            />
                            <Fact
                                label={t('Last visit')}
                                value={lastVisit(t, customer.lastVisitDays)}
                            />
                            <Fact
                                label={t('Rewards redeemed')}
                                value={customer.rewardsRedeemed}
                            />
                        </dl>

                        <section
                            aria-labelledby="cards"
                            className="flex flex-col gap-3 rounded-card border bg-card px-4 py-3.5"
                        >
                            <h2 id="cards" className="font-semibold">
                                {t('Cards')}
                            </h2>
                            {cards.map((card) => (
                                <CardItem key={card.id} card={card} />
                            ))}
                        </section>
                    </div>
                </div>
            </div>
        </>
    );
}

function Fact({ label, value }: { label: string; value: string | number }) {
    return (
        <div className="flex flex-col">
            <dd className="order-1 font-display text-xl font-bold">{value}</dd>
            <dt className="order-2 text-xs text-muted-foreground">{label}</dt>
        </div>
    );
}

function CardItem({ card }: { card: CardProgress }) {
    const { t, tn } = useTranslation();
    const total = card.stamps + (card.toReward ?? 0);
    const progress = total === 0 ? 1 : Math.min(card.stamps / total, 1);

    return (
        <div className="flex flex-col gap-1.5" data-test="customer-card">
            <div className="flex justify-between gap-3 text-sm">
                <span className="font-medium">
                    <bdi>{card.name}</bdi>
                </span>
                {card.toReward === null ? null : (
                    <span dir="ltr" className="tabular-nums">
                        {card.stamps} / {total}
                    </span>
                )}
            </div>
            <div className="h-2 overflow-hidden rounded-full bg-muted">
                <div
                    className="h-full w-(--progress) rounded-full bg-stamp"
                    style={
                        { '--progress': `${progress * 100}%` } as CSSProperties
                    }
                />
            </div>
            <p className="text-xs text-muted-foreground">
                {card.toReward === null
                    ? t('Every reward earned.')
                    : tn(
                          ':count more stamp to :reward.|:count more stamps to :reward.',
                          { reward: <bdi>{card.rewardText}</bdi> },
                          { count: card.toReward },
                      )}
            </p>
        </div>
    );
}

function HistoryItem({ event }: { event: HistoryEvent }) {
    const { t, tn, locale } = useTranslation();
    const when = momentLabel(locale, event.at, {
        today: (time) => t('Today at :time', { time }),
        other: (date, time) => t(':date at :time', { date, time }),
    });
    const Icon =
        event.kind === 'redeemed'
            ? Gift
            : event.source === 'correction'
              ? Undo2
              : event.source === 'manual'
                ? Hand
                : Stamp;

    return (
        <li className="flex gap-3 py-3" data-test="customer-event">
            <span
                aria-hidden="true"
                className={cn(
                    'flex size-9 shrink-0 items-center justify-center rounded-md',
                    event.kind === 'redeemed' && 'bg-success/15',
                    event.source === 'correction' && 'bg-warning/15',
                    event.kind === 'stamp' &&
                        event.source !== 'correction' &&
                        'bg-stamp/25',
                )}
            >
                <Icon className="size-4 rtl:-scale-x-100" />
            </span>
            <div className="flex min-w-0 flex-col gap-0.5">
                <p className="text-sm font-semibold">
                    {event.kind === 'redeemed'
                        ? tn(':reward redeemed', {
                              reward: <bdi>{event.card}</bdi>,
                          })
                        : stampTitle(t, event)}
                </p>
                <p className="text-xs text-muted-foreground">
                    {when} · {how(tn, event)}
                </p>
                {event.reason ? (
                    <p className="mt-1 w-fit rounded-md bg-muted px-2.5 py-1 text-sm">
                        <bdi>{event.reason}</bdi>
                    </p>
                ) : null}
            </div>
        </li>
    );
}

function stampTitle(t: TranslateFn, event: HistoryEvent): string {
    const count = Math.abs(event.qty);

    switch (event.source) {
        case 'manual':
            return t(
                '+:count stamp, added by hand|+:count stamps, added by hand',
                {
                    count,
                },
            );
        case 'correction':
            return t('−:count stamp, taken back|−:count stamps, taken back', {
                count,
            });
        case 'bonus':
        case 'birthday':
        case 'referral':
            return t('+:count bonus stamp|+:count bonus stamps', { count });
        default:
            return t('+:count stamp|+:count stamps', { count });
    }
}

/** How it happened: where, and who on the staff, if anyone; their names in their own direction. */
function how(tn: TranslateNodesFn, event: HistoryEvent): ReactNode {
    const where = <bdi>{event.location ?? ''}</bdi>;
    const staff = <bdi>{event.by ?? ''}</bdi>;

    if (event.kind === 'redeemed') {
        return event.byScan && event.by
            ? tn(':staff scanned their QR, :location', {
                  staff,
                  location: where,
              })
            : tn('with their own tap, :location', { location: where });
    }

    switch (event.source) {
        case 'nfc':
            return tn('tap at the stamper, :location', { location: where });
        case 'qr':
            return event.by
                ? tn(':staff scanned their QR, :location', {
                      staff,
                      location: where,
                  })
                : where;
        case 'manual':
        case 'correction':
            return event.by
                ? tn(':staff, :location', { staff, location: where })
                : where;
        default:
            return where;
    }
}
