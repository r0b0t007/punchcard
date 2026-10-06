import { Form, Head, Link, router, usePoll } from '@inertiajs/react';
import { ChevronLeft, Mail } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import CountdownRing from '@/components/rewards/countdown-ring';
import RedeemedReward from '@/components/rewards/redeemed-reward';
import BrandMonogram from '@/components/loyalty-card/brand-monogram';
import TapScreen from '@/components/tap/tap-screen';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/hooks/use-translation';
import type { LocalMoment } from '@/lib/local-moment';
import { index as myRewards, redeem } from '@/routes/rewards';
import { close } from '@/routes/rewards/redeem';
import { send } from '@/routes/verification';

/** One of the customer's rewards on the redeem screen (DescribeCustomerReward). */
type CustomerReward = {
    id: number;
    rewardText: string;
    businessName: string;
    brandColor: string | null;
    status: 'available' | 'redeemed' | 'expired';
    verified: boolean;
    /** OpenRedeemWindow::SECONDS */
    windowSeconds: number;
    secondsLeft: number;
    redeemed: {
        at: LocalMoment;
        locationName: string | null;
        liveSeconds: number;
    } | null;
};

/**
 * C4, the redeem screen (CHW-26, Claude Design "Punchcard Tap Flow" 04 and
 * "Punchcard Redemption"): tap the stamper while the ring runs. The tap may
 * open in another browser, so this screen asks every two seconds whether the
 * reward was redeemed, then shows the redemption. Time's up offers to try
 * again; an unverified email is asked to verify first. No staff QR yet
 * (CHW-28/30).
 */
export default function RewardRedeem({
    reward,
    status,
}: {
    reward: CustomerReward;
    status?: string;
}) {
    const { t } = useTranslation();
    // The server counts the window; the ring may reach zero first, between two polls.
    const [ringDone, setRingDone] = useState(false);
    const timedOut = reward.secondsLeft === 0 || ringDone;
    const waiting =
        reward.status === 'available' && reward.verified && !timedOut;
    const onTimeUp = useCallback(() => {
        setRingDone(true);
        // A tap in the last seconds lands between two polls: look once more.
        router.reload({ only: ['reward'] });
    }, []);
    const { start, stop } = usePoll(
        2000,
        { only: ['reward'] },
        { autoStart: false },
    );

    // Live only on the screen that waited for the tap and saw it land: a reload, another
    // phone or a later visit shows the redemption as already made.
    const [sawRedemption, setSawRedemption] = useState(false);
    const waited = useRef(false);

    useEffect(() => {
        if (waiting) {
            waited.current = true;
        } else if (reward.status === 'redeemed' && waited.current) {
            setSawRedemption(true);
        }
    }, [waiting, reward.status]);

    // A new window (try again) runs the ring again.
    useEffect(() => {
        if (reward.secondsLeft > 0) {
            setRingDone(false);
        }
    }, [reward.secondsLeft]);

    useEffect(() => {
        if (waiting) {
            start();
        } else {
            stop();
        }
    }, [waiting, start, stop]);

    return (
        <>
            <Head title={reward.rewardText} />
            <TapScreen
                actions={
                    reward.status === 'redeemed' ? (
                        <Button asChild size="lg">
                            <Link href={myRewards()}>{t('Done')}</Link>
                        </Button>
                    ) : null
                }
            >
                <div className="-ms-3 -mt-4">
                    {/* Back closes the window: the next tap stamps again. */}
                    <Form {...close.form(reward.id)}>
                        <Button
                            type="submit"
                            variant="ghost"
                            size="icon"
                            aria-label={t('Back')}
                        >
                            <ChevronLeft className="size-6 rtl:-scale-x-100" />
                        </Button>
                    </Form>
                </div>
                {reward.status === 'redeemed' && reward.redeemed ? (
                    <RedeemedReward
                        key={sawRedemption ? 'seen' : 'loaded'}
                        rewardText={reward.rewardText}
                        place={[
                            reward.businessName,
                            reward.redeemed.locationName,
                        ]
                            .filter(Boolean)
                            .join(' · ')}
                        redeemedAt={reward.redeemed.at}
                        liveSeconds={
                            sawRedemption ? reward.redeemed.liveSeconds : 0
                        }
                    />
                ) : reward.status !== 'available' ? (
                    <p className="text-lg">
                        {t('This reward is no longer available.')}
                    </p>
                ) : !reward.verified ? (
                    <VerifyEmail reward={reward} status={status} />
                ) : (
                    <div className="flex flex-col items-center gap-6 text-center">
                        <RewardChip reward={reward} />
                        {timedOut ? (
                            <TimeUp reward={reward} />
                        ) : (
                            <>
                                <h1 className="font-display text-3xl leading-tight font-bold text-balance">
                                    {t('Tap the stamper now')}
                                </h1>
                                <CountdownRing
                                    seconds={reward.secondsLeft}
                                    total={reward.windowSeconds}
                                    onDone={onTimeUp}
                                />
                            </>
                        )}
                    </div>
                )}
            </TapScreen>
        </>
    );
}

/** The reward and its café, with the café's monogram in its brand colour (ADR 0007). */
function RewardChip({ reward }: { reward: CustomerReward }) {
    return (
        <p className="flex items-center gap-2.5 rounded-full bg-accent py-1.5 ps-1.5 pe-4">
            <BrandMonogram
                name={reward.businessName}
                brandColor={reward.brandColor}
                className="size-7"
            />
            <span className="font-medium">
                {reward.rewardText} · {reward.businessName}
            </span>
        </p>
    );
}

/** The window ran out: the reward is still saved. */
function TimeUp({ reward }: { reward: CustomerReward }) {
    const { t } = useTranslation();

    return (
        <>
            <h1 className="font-display text-3xl leading-tight font-bold">
                {t("Time's up")}
            </h1>
            <p className="text-lg text-pretty text-muted-foreground">
                {t(
                    "Your :reward is still saved. Try again when you're at the counter.",
                    { reward: reward.rewardText },
                )}
            </p>
            <Form {...redeem.form(reward.id)} className="w-full">
                {({ processing }) => (
                    <Button
                        type="submit"
                        size="lg"
                        className="w-full"
                        disabled={processing}
                    >
                        {t('Try again')}
                    </Button>
                )}
            </Form>
        </>
    );
}

/** Redeeming needs a verified email; stamps don't. */
function VerifyEmail({
    reward,
    status,
}: {
    reward: CustomerReward;
    status?: string;
}) {
    const { t } = useTranslation();

    return (
        <div className="flex flex-col gap-4">
            <span
                aria-hidden="true"
                className="grid size-14 place-items-center rounded-full bg-accent"
            >
                <Mail className="size-7" />
            </span>
            <h1 className="font-display text-3xl leading-tight font-bold">
                {t('Verify your email to redeem')}
            </h1>
            <p className="text-lg text-pretty text-muted-foreground">
                {t(
                    'We sent a link to your email. Open it, then come back for your :reward.',
                    { reward: reward.rewardText },
                )}
            </p>
            {status === 'verification-link-sent' ? (
                <p role="status" className="font-medium text-success">
                    {t('A new link is on its way.')}
                </p>
            ) : null}
            <Form {...send.form()}>
                {({ processing }) => (
                    <Button
                        type="submit"
                        size="lg"
                        className="w-full"
                        disabled={processing}
                    >
                        {t('Send the link again')}
                    </Button>
                )}
            </Form>
        </div>
    );
}
