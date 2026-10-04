import { Head } from '@inertiajs/react';
import TapScreen from '@/components/tap/tap-screen';
import type { TapCard } from '@/components/tap/tap-screen';
import { useTranslation } from '@/hooks/use-translation';

/** C2: the stamp landed (CHW-25). */
export default function TapStamped({
    card,
    given,
    rewards,
}: {
    card: TapCard | null;
    given: number;
    rewards: string[];
}) {
    const { t } = useTranslation();
    const title = t(':count stamp added|:count stamps added', {
        count: given,
    });

    return (
        <>
            <Head title={title} />
            <TapScreen title={title} card={card}>
                {rewards.map((reward) => (
                    <p key={reward} className="font-display text-xl font-bold">
                        {t('Reward unlocked: :reward', { reward })}
                    </p>
                ))}
            </TapScreen>
        </>
    );
}
