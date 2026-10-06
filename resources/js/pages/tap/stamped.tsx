import { Form, Head, Link } from '@inertiajs/react';
import TapScreen, { TapLoyaltyCard } from '@/components/tap/tap-screen';
import type { TapCard } from '@/components/tap/tap-screen';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/hooks/use-translation';
import { momentLabel } from '@/lib/local-moment';
import type { LocalMoment } from '@/lib/local-moment';
import { index as myRewards, redeem } from '@/routes/rewards';

/** A reward this stamp unlocked, and where it stands now (DescribeTap). */
type UnlockedReward = {
    id: number;
    text: string;
    status: 'available' | 'redeemed' | 'expired';
};

/**
 * C2: the stamp landed (CHW-25). C3 when it unlocked a reward (CHW-26): the
 * card glows complete, the reward is announced, and it can be redeemed now
 * or kept for later.
 */
export default function TapStamped({
    card,
    given,
    rewards,
    stampedAt,
    stampsBefore,
    fresh,
}: {
    card: TapCard | null;
    given: number;
    rewards: UnlockedReward[];
    stampedAt: LocalMoment | null;
    stampsBefore: number;
    fresh: boolean;
}) {
    const { t, locale } = useTranslation();
    const added = t(':count stamp added|:count stamps added', {
        count: given,
    });
    const remaining = card
        ? Math.max(card.stampsRequired - card.stampsCollected, 0)
        : 0;
    const toRedeem = rewards.find((reward) => reward.status === 'available');

    return (
        <>
            <Head title={rewards.length > 0 ? t('Reward unlocked') : added} />
            <TapScreen
                actions={
                    toRedeem ? (
                        <>
                            <Form {...redeem.form(toRedeem.id)}>
                                {({ processing }) => (
                                    <Button
                                        type="submit"
                                        size="lg"
                                        className="w-full"
                                        disabled={processing}
                                    >
                                        {t('Redeem now')}
                                    </Button>
                                )}
                            </Form>
                            <Button asChild size="lg" variant="outline">
                                <Link href={myRewards()}>
                                    {t('Save for later')}
                                </Link>
                            </Button>
                        </>
                    ) : null
                }
            >
                {stampedAt ? (
                    <p className="flex items-center gap-2 self-start rounded-full bg-accent px-4 py-2 font-medium text-success">
                        <span
                            aria-hidden="true"
                            className="size-2 rounded-full bg-success"
                        />
                        {momentLabel(locale, stampedAt, {
                            today: (time) => t('Stamped at :time', { time }),
                            other: (date, time) =>
                                t('Stamped on :date at :time', { date, time }),
                        })}
                    </p>
                ) : null}
                {card ? (
                    <div className="relative">
                        {rewards.length > 0 ? (
                            // The completed card glows (C3).
                            <div
                                aria-hidden="true"
                                className="absolute -inset-4 rounded-4xl bg-stamp/30 blur-2xl"
                            />
                        ) : null}
                        <div className="relative">
                            {/* The stamp lands on the first look only, not on a reload or a later visit. */}
                            <TapLoyaltyCard
                                card={card}
                                landingFrom={fresh ? stampsBefore : undefined}
                            />
                        </div>
                    </div>
                ) : null}
                {rewards.length > 0 ? (
                    <div className="flex flex-col gap-3">
                        <p className="self-start rounded-full bg-stamp px-4 py-2 font-semibold text-stamp-foreground">
                            {t('Reward unlocked')}
                        </p>
                        <h1 className="flex flex-col gap-2 font-display text-5xl leading-none font-extrabold">
                            {rewards.map((reward) => (
                                <span key={reward.id}>{reward.text}</span>
                            ))}
                        </h1>
                        <p className="text-lg text-muted-foreground">
                            {toRedeem
                                ? t(
                                      'Have it today or keep it for your next visit.',
                                  )
                                : rewards.some(
                                        (reward) =>
                                            reward.status === 'redeemed',
                                    )
                                  ? t('This reward was already redeemed.')
                                  : t('This reward is no longer available.')}
                        </p>
                    </div>
                ) : card ? (
                    <div className="flex flex-col gap-2">
                        {/* A progressive card past its last tier keeps counting: no "of", nothing left to go. */}
                        <h1 className="font-display text-5xl leading-none font-bold tabular-nums">
                            {remaining > 0
                                ? t(':collected of :required', {
                                      collected: card.stampsCollected,
                                      required: card.stampsRequired,
                                  })
                                : t(':count stamp|:count stamps', {
                                      count: card.stampsCollected,
                                  })}
                        </h1>
                        {remaining > 0 ? (
                            <p className="text-xl">
                                {t(
                                    ':count more stamp to your reward|:count more stamps to your reward',
                                    { count: remaining },
                                )}
                            </p>
                        ) : null}
                    </div>
                ) : (
                    <h1 className="font-display text-5xl leading-none font-bold">
                        {added}
                    </h1>
                )}
            </TapScreen>
        </>
    );
}
