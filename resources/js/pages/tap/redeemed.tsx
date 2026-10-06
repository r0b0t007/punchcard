import { Head, Link } from '@inertiajs/react';
import RedeemedReward from '@/components/rewards/redeemed-reward';
import TapScreen from '@/components/tap/tap-screen';
import type { TapCard } from '@/components/tap/tap-screen';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/hooks/use-translation';
import type { LocalMoment } from '@/lib/local-moment';
import { home } from '@/routes';
import { index as myRewards } from '@/routes/rewards';

/**
 * The tap redeemed a reward instead of stamping (CHW-26): the screen staff
 * glance at: live on its first look only (TapSession), and only as long as
 * the redemption is new (DescribeTap's liveSeconds).
 */
export default function TapRedeemed({
    card,
    rewardText,
    redeemedAt,
    liveSeconds,
    fresh,
}: {
    card: TapCard | null;
    rewardText: string | null;
    redeemedAt: LocalMoment | null;
    liveSeconds: number;
    fresh: boolean;
}) {
    const { t } = useTranslation();
    const place = [card?.businessName, card?.locationName]
        .filter(Boolean)
        .join(' · ');

    return (
        <>
            <Head title={t('Reward redeemed')} />
            <TapScreen
                actions={
                    <>
                        <Button asChild size="lg">
                            <Link href={home()}>{t('Done')}</Link>
                        </Button>
                        <Button asChild size="lg" variant="link">
                            <Link href={myRewards()}>{t('My rewards')}</Link>
                        </Button>
                    </>
                }
            >
                {redeemedAt && rewardText ? (
                    <RedeemedReward
                        rewardText={rewardText}
                        place={place}
                        redeemedAt={redeemedAt}
                        liveSeconds={fresh ? liveSeconds : 0}
                    />
                ) : (
                    <h1 className="font-display text-5xl leading-none font-bold">
                        {t('Reward redeemed')}
                    </h1>
                )}
            </TapScreen>
        </>
    );
}
