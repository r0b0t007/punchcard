import { Link } from '@inertiajs/react';
import { ChevronRight, CloudOff, Gift, SmartphoneNfc } from 'lucide-react';
import type { ReactNode } from 'react';
import { TapLoyaltyCard } from '@/components/tap/tap-screen';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/hooks/use-translation';
import { show } from '@/routes/app/cards';
import { index as rewards } from '@/routes/rewards';
import type { MyCard } from '@/types/my-cards';

/**
 * C5 My cards (CHW-27): a banner for the rewards ready, then every card the
 * customer holds, the most recently used first, each in its program's
 * colours. Online each card opens in full; offline (CHW-35) the cards kept
 * on the phone are shown as they were, with when, and nothing to open.
 */
export default function MyCardsView({
    firstName,
    rewardsReady,
    cards,
    offline,
}: {
    firstName: string;
    rewardsReady: number;
    cards: MyCard[];
    /** Shown from the copy kept on the phone: when it was saved, and a way to try again. */
    offline?: { updatedAt: string; onRetry: () => void };
}) {
    const { t } = useTranslation();

    const rewardsBanner = (
        <>
            <Gift aria-hidden="true" className="size-5 shrink-0" />
            <span className="flex-1">
                {t(':count reward ready|:count rewards ready', {
                    count: rewardsReady,
                })}
            </span>
        </>
    );

    return (
        <main className="mx-auto flex max-w-md flex-col gap-6 px-4 pt-8">
            {offline ? (
                <section
                    role="status"
                    className="flex items-center gap-3 rounded-card border p-4 text-sm"
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

            <header className="flex flex-col gap-1">
                <h1 className="font-display text-2xl font-bold">
                    {firstName
                        ? t('Hello :name', { name: firstName })
                        : t('Hello')}
                </h1>
                <p className="text-sm text-muted-foreground">
                    {t('Your loyalty cards')}
                </p>
            </header>

            {rewardsReady > 0 ? (
                offline ? (
                    <div className="flex items-center gap-3 rounded-card bg-stamp p-4 font-semibold text-stamp-foreground">
                        {rewardsBanner}
                    </div>
                ) : (
                    <Link
                        href={rewards()}
                        className="flex items-center gap-3 rounded-card bg-stamp p-4 font-semibold text-stamp-foreground"
                    >
                        {rewardsBanner}
                        <ChevronRight
                            aria-hidden="true"
                            className="size-5 shrink-0 rtl:rotate-180"
                        />
                    </Link>
                )
            ) : null}

            {cards.length === 0 ? (
                <section className="flex flex-col items-center gap-3 rounded-card border border-dashed p-8 text-center">
                    <SmartphoneNfc
                        aria-hidden="true"
                        className="size-10 text-muted-foreground"
                    />
                    <h2 className="font-semibold">{t('No cards yet')}</h2>
                    <p className="text-sm text-muted-foreground">
                        {t(
                            'At a café that uses punchcard, hold your phone to the stamper at the counter: your first stamp starts your card.',
                        )}
                    </p>
                </section>
            ) : (
                <ul className="flex flex-col gap-6">
                    {cards.map((myCard) => (
                        <li key={myCard.id} className="flex flex-col gap-2">
                            {offline ? (
                                <TapLoyaltyCard
                                    card={myCard.card}
                                    logoUrl={myCard.logoUrl}
                                />
                            ) : (
                                <Link
                                    href={show(myCard.id)}
                                    aria-label={t('Open :card', {
                                        card: `${myCard.card.businessName}, ${myCard.card.cardName}`,
                                    })}
                                    className="rounded-card focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                >
                                    <TapLoyaltyCard
                                        card={myCard.card}
                                        logoUrl={myCard.logoUrl}
                                    />
                                </Link>
                            )}
                            <CardFacts myCard={myCard} />
                        </li>
                    ))}
                </ul>
            )}
        </main>
    );
}

function CardFacts({ myCard }: { myCard: MyCard }): ReactNode {
    const { t } = useTranslation();

    return (
        <div className="flex flex-wrap items-center gap-2 px-1 text-sm text-muted-foreground">
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
                <Badge>
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
