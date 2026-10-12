import { Head, Link } from '@inertiajs/react';
import { ChevronRight, Gift, SmartphoneNfc } from 'lucide-react';
import { TapLoyaltyCard } from '@/components/tap/tap-screen';
import type { TapCard } from '@/components/tap/tap-screen';
import { Badge } from '@/components/ui/badge';
import { useTranslation } from '@/hooks/use-translation';
import { index as rewards } from '@/routes/rewards';

type MyCard = {
    /** The enrollment. */
    id: number;
    card: TapCard;
    logoUrl: string | null;
    rewardsReady: number;
    /** Local days since the last stamp in person; null before any. */
    lastVisitDaysAgo: number | null;
    paused: boolean;
};

/**
 * C5 My cards (CHW-27): the customer's home. A banner for the rewards ready,
 * then every card they hold, the most recently used first, each in its
 * program's colours.
 */
export default function MyCards({
    firstName,
    rewardsReady,
    cards,
}: {
    firstName: string;
    rewardsReady: number;
    cards: MyCard[];
}) {
    const { t } = useTranslation();

    return (
        <>
            <Head title={t('My cards')} />
            <main className="mx-auto flex max-w-md flex-col gap-6 px-4 pt-8">
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
                    <Link
                        href={rewards()}
                        className="flex items-center gap-3 rounded-card bg-stamp p-4 font-semibold text-stamp-foreground"
                    >
                        <Gift aria-hidden="true" className="size-5 shrink-0" />
                        <span className="flex-1">
                            {t(':count reward ready|:count rewards ready', {
                                count: rewardsReady,
                            })}
                        </span>
                        <ChevronRight
                            aria-hidden="true"
                            className="size-5 shrink-0 rtl:rotate-180"
                        />
                    </Link>
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
                                <TapLoyaltyCard
                                    card={myCard.card}
                                    logoUrl={myCard.logoUrl}
                                />
                                <div className="flex flex-wrap items-center gap-2 px-1 text-sm text-muted-foreground">
                                    <span>
                                        {myCard.lastVisitDaysAgo === null
                                            ? t('No visit yet')
                                            : myCard.lastVisitDaysAgo === 0
                                              ? t('Last visit today')
                                              : t(
                                                    'Last visit :count day ago|Last visit :count days ago',
                                                    {
                                                        count: myCard.lastVisitDaysAgo,
                                                    },
                                                )}
                                    </span>
                                    {myCard.rewardsReady > 0 ? (
                                        <Badge>
                                            {t(
                                                ':count reward ready|:count rewards ready',
                                                { count: myCard.rewardsReady },
                                            )}
                                        </Badge>
                                    ) : null}
                                    {myCard.paused ? (
                                        <Badge variant="secondary">
                                            {t('Paused by the café')}
                                        </Badge>
                                    ) : null}
                                </div>
                            </li>
                        ))}
                    </ul>
                )}
            </main>
        </>
    );
}
