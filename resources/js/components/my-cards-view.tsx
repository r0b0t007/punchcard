import { Link } from '@inertiajs/react';
import { CloudOff, SmartphoneNfc, QrCode } from 'lucide-react';
import type { CSSProperties, ReactNode } from 'react';
import BrandMonogram from '@/components/loyalty-card/brand-monogram';
import { TapLoyaltyCard } from '@/components/tap/tap-screen';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/hooks/use-translation';
import { brandMarkOn, cardInks, SURFACE } from '@/lib/color';
import { closestToReward } from '@/lib/my-cards';
import { show } from '@/routes/app/cards';
import { qr as memberQr } from '@/routes/app';
import { index as rewards } from '@/routes/rewards';
import type { MyCard } from '@/types/my-cards';

/**
 * C5 My cards (CHW-27), "next reward first" (CHW-217; Claude Design
 * `CH MyCards v2`, review 1a): the rewards ready and the number of cards
 * under the greeting, the card closest to a reward in full, then every other
 * card as a row with its progress in its brand colour. Online each card
 * opens in full; offline (CHW-35) the cards kept on the phone are shown as
 * they were, with when, and nothing to open.
 */
export default function MyCardsView({
    firstName,
    rewardsReady,
    cards,
    offline,
    notices,
}: {
    firstName: string;
    rewardsReady: number;
    cards: MyCard[];
    /** Online only: notices above the cards, such as a reward staff redeemed with a scan (CHW-228). */
    notices?: ReactNode;
    /** Shown from the copy kept on the phone: when it was saved, and a way to try again. */
    offline?: { updatedAt: string; onRetry: () => void };
}) {
    const { t } = useTranslation();
    const { closest, others } = closestToReward(cards);

    const rewardsLine = t(':count reward ready|:count rewards ready', {
        count: rewardsReady,
    });

    return (
        <main className="mx-auto flex max-w-md flex-col gap-4.5 px-6 pt-5">
            {offline ? (
                <section
                    role="status"
                    className="flex items-center gap-3 rounded-2xl border bg-card px-3.5 py-3 text-sm"
                >
                    <CloudOff
                        aria-hidden="true"
                        className="size-5 shrink-0 text-muted-foreground"
                    />
                    <p className="flex-1">
                        {t('Offline. Last updated :time.', {
                            time: offline.updatedAt,
                        })}
                    </p>
                    <Button
                        variant="outline"
                        size="sm"
                        onClick={offline.onRetry}
                    >
                        {t('Try again')}
                    </Button>
                </section>
            ) : null}

            <header className="flex items-start justify-between gap-3">
                <div className="flex flex-col gap-1">
                    <h1 className="font-display text-3xl leading-tight font-bold">
                        {firstName
                            ? t('Hello :name', { name: firstName })
                            : t('Hello')}
                    </h1>
                    {cards.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            {t('Your loyalty cards')}
                        </p>
                    ) : (
                        // Laid out as a row, so Arabic keeps the parts' order.
                        <p className="flex flex-wrap gap-1.5 text-sm text-muted-foreground">
                            {rewardsReady > 0 ? (
                                <>
                                    {offline ? (
                                        <span>{rewardsLine}</span>
                                    ) : (
                                        <Link
                                            href={rewards()}
                                            className="font-medium text-foreground underline underline-offset-3"
                                        >
                                            {rewardsLine}
                                        </Link>
                                    )}
                                    <span aria-hidden="true">·</span>
                                </>
                            ) : null}
                            <span>
                                {t(':count card|:count cards', {
                                    count: cards.length,
                                })}
                            </span>
                        </p>
                    )}
                </div>
                {/* "My QR" (CHW-28, C10), for a phone without NFC; the code comes from the server, so never offline. */}
                {offline ? null : (
                    <Link
                        href={memberQr()}
                        className="flex h-10 shrink-0 items-center gap-2 rounded-full border bg-card px-3.5 text-sm font-medium"
                        data-test="my-qr-link"
                    >
                        <QrCode aria-hidden="true" className="size-4" />
                        {t('My QR')}
                    </Link>
                )}
            </header>

            {notices}

            {cards.length === 0 ? (
                <section className="flex flex-col items-center gap-3 rounded-card border border-dashed bg-card px-6 py-8 text-center">
                    <SmartphoneNfc
                        aria-hidden="true"
                        className="size-10 text-muted-foreground"
                    />
                    <h2 className="font-display text-heading font-bold">
                        {t('No cards yet')}
                    </h2>
                    <p className="text-muted-foreground">
                        {t(
                            'At a café that uses punchcard, hold your phone to the stamper at the counter: your first stamp starts your card.',
                        )}
                    </p>
                </section>
            ) : null}

            {closest ? (
                <section className="flex flex-col gap-2.5">
                    <h2 className="text-xs font-semibold tracking-wider text-muted-foreground uppercase">
                        {t('Closest to a reward')}
                    </h2>
                    <OpenCard myCard={closest} offline={!!offline}>
                        <TapLoyaltyCard
                            card={closest.card}
                            logoUrl={closest.logoUrl}
                        />
                    </OpenCard>
                    <CardFacts myCard={closest} />
                </section>
            ) : null}

            {others.length > 0 ? (
                <ul className="flex flex-col gap-2.5">
                    {others.map((myCard) => (
                        <li key={myCard.id}>
                            {/* Named by its content, so the count and the chips are read out too. */}
                            <OpenCard
                                myCard={myCard}
                                offline={!!offline}
                                named={false}
                            >
                                <CardRow myCard={myCard} />
                            </OpenCard>
                        </li>
                    ))}
                </ul>
            ) : null}
        </main>
    );
}

/** A card opens in full (C6) online; offline every page behind a link needs the network. */
function OpenCard({
    myCard,
    offline,
    named = true,
    children,
}: {
    myCard: MyCard;
    offline: boolean;
    /** Labelled "Open :card"; a link without the label is named by its content. */
    named?: boolean;
    children: ReactNode;
}) {
    const { t } = useTranslation();

    if (offline) {
        return <div>{children}</div>;
    }

    return (
        <Link
            href={show(myCard.id)}
            aria-label={
                named
                    ? t('Open :card', {
                          card: `${myCard.card.businessName}, ${myCard.card.cardName}`,
                      })
                    : undefined
            }
            className="block rounded-card focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
        >
            {children}
        </Link>
    );
}

/**
 * Any card but the closest: its monogram, the count, and a bar in its brand
 * colour (ADR 0007), or its text colour where the brand would blend into the
 * light or dark surface. A cyclic card whose reward is ready has started
 * again, so the count is the new one; only a progressive card with every
 * tier earned shows a full bar. The card's name follows the café's, so two
 * cards of one café tell apart.
 */
function CardRow({ myCard }: { myCard: MyCard }) {
    const { card } = myCard;
    const { brand, foreground } = cardInks(
        card.brandColor,
        card.brandForeground,
    );
    const progress = Math.min(card.stampsCollected / card.stampsRequired, 1);
    // Runtime values enter the UI only as CSS variables (design-tokens skill).
    const variables = {
        '--bar-light': brandMarkOn(SURFACE.light, brand, foreground),
        '--bar-dark': brandMarkOn(SURFACE.dark, brand, foreground),
        '--progress': `${progress * 100}%`,
    } as CSSProperties;

    return (
        <div
            style={variables}
            className="flex items-center gap-3 rounded-card border bg-card p-3.5"
        >
            <BrandMonogram
                name={card.businessName}
                brandColor={card.brandColor}
                brandForeground={card.brandForeground}
                className="size-10 text-xl"
            />
            <div className="flex min-w-0 flex-1 flex-col gap-1.5">
                <div className="flex items-baseline justify-between gap-2">
                    <span className="truncate">
                        <span className="font-semibold">
                            {card.businessName}
                        </span>
                        {card.cardName ? (
                            <span className="text-muted-foreground">
                                {' · '}
                                {card.cardName}
                            </span>
                        ) : null}
                    </span>
                    <bdi
                        dir="ltr"
                        className="shrink-0 font-display text-lg font-bold tabular-nums"
                    >
                        {card.stampsCollected}/{card.stampsRequired}
                    </bdi>
                </div>
                <div
                    aria-hidden="true"
                    className="h-1.5 overflow-hidden rounded-full bg-secondary"
                >
                    <div className="h-full w-(--progress) rounded-full bg-(--bar-light) dark:bg-(--bar-dark)" />
                </div>
                <CardFacts myCard={myCard} />
            </div>
        </div>
    );
}

function CardFacts({ myCard }: { myCard: MyCard }): ReactNode {
    const { t } = useTranslation();

    return (
        <div className="flex flex-wrap items-center gap-2 text-sm text-muted-foreground">
            <span>
                {myCard.lastVisitDaysAgo === null
                    ? t('No visit yet')
                    : myCard.lastVisitDaysAgo === 0
                      ? t('Last visit today')
                      : t(
                            'Last visit :count day ago|Last visit :count days ago',
                            { count: myCard.lastVisitDaysAgo },
                        )}
            </span>
            {myCard.rewardsReady > 0 ? (
                <Badge variant="stamp">
                    {t(':count reward ready|:count rewards ready', {
                        count: myCard.rewardsReady,
                    })}
                </Badge>
            ) : null}
            {myCard.paused ? (
                <Badge variant="secondary">{t('Paused by the café')}</Badge>
            ) : null}
        </div>
    );
}
