import { Form, Head, Link } from '@inertiajs/react';
import type { CSSProperties } from 'react';
import { monogramOf } from '@/components/loyalty-card/monogram';
import TapScreen from '@/components/tap/tap-screen';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/hooks/use-translation';
import { cardInks } from '@/lib/color';
import { home } from '@/routes';
import { redeem } from '@/routes/rewards';

/** A reward still to redeem (ListCustomerRewards). */
type SavedReward = {
    id: number;
    rewardText: string;
    businessName: string;
    brandColor: string | null;
    /** Y-m-d */
    unlockedOn: string;
};

/**
 * My rewards (CHW-26, a first cut of C9; Claude Design "Punchcard
 * Redemption" 09): the rewards saved for later, each redeemed at the counter.
 */
export default function MyRewards({ rewards }: { rewards: SavedReward[] }) {
    const { t, locale } = useTranslation();
    // A calendar date: format it in UTC so no timezone shifts the day.
    const since = new Intl.DateTimeFormat(locale, {
        day: 'numeric',
        month: 'short',
        timeZone: 'UTC',
    });

    return (
        <>
            <Head title={t('My rewards')} />
            <TapScreen
                actions={
                    <Button asChild size="lg" variant="link">
                        <Link href={home()}>{t('Done')}</Link>
                    </Button>
                }
            >
                <div className="flex flex-col gap-1.5">
                    <h1 className="font-display text-4xl leading-tight font-bold">
                        {rewards.length > 0
                            ? t('My rewards')
                            : t('No rewards yet')}
                    </h1>
                    <p className="text-lg text-muted-foreground">
                        {rewards.length > 0
                            ? t('Ready to redeem. Tap Redeem at the counter.')
                            : t('Fill a card and your reward waits here.')}
                    </p>
                </div>
                {rewards.length > 0 ? (
                    <ul className="flex flex-col gap-3">
                        {rewards.map((reward) => {
                            const { brand, foreground } = cardInks(
                                reward.brandColor,
                            );
                            const brandVariables = {
                                '--card-brand': brand,
                                '--card-brand-foreground': foreground,
                            } as CSSProperties;

                            return (
                                <li
                                    key={reward.id}
                                    className="flex items-center gap-3.5 rounded-card border border-border bg-card p-4"
                                >
                                    <span
                                        aria-hidden="true"
                                        style={brandVariables}
                                        className="grid size-11 shrink-0 place-items-center rounded-full bg-card-brand font-display text-xl font-bold text-card-brand-foreground"
                                    >
                                        {monogramOf(reward.businessName)}
                                    </span>
                                    <div className="min-w-0 flex-1">
                                        <p className="truncate text-lg font-semibold">
                                            {reward.rewardText}
                                        </p>
                                        <p className="text-sm text-muted-foreground">
                                            {t(':business · since :date', {
                                                business: reward.businessName,
                                                date: since.format(
                                                    new Date(
                                                        `${reward.unlockedOn}T00:00:00Z`,
                                                    ),
                                                ),
                                            })}
                                        </p>
                                    </div>
                                    <Form {...redeem.form(reward.id)}>
                                        {({ processing }) => (
                                            <Button
                                                type="submit"
                                                disabled={processing}
                                            >
                                                {t('Redeem')}
                                            </Button>
                                        )}
                                    </Form>
                                </li>
                            );
                        })}
                    </ul>
                ) : null}
            </TapScreen>
        </>
    );
}
