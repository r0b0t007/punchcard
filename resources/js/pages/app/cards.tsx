import { Head, usePage } from '@inertiajs/react';
import { useEffect } from 'react';
import MyCardsView from '@/components/my-cards-view';
import RedeemNotice from '@/components/rewards/redeem-notice';
import type { RedeemNoticeData } from '@/components/rewards/redeem-notice';
import { useTranslation } from '@/hooks/use-translation';
import {
    saveSnapshot,
    saveTranslations,
    snapshotOf,
} from '@/lib/offline-cards';
import type { MyCard } from '@/types/my-cards';

/**
 * C5 My cards (CHW-27): the customer's home. The rewards ready, the card
 * closest to a reward in full, then every other card they hold, the most
 * recently used first, each in its program's colours (MyCardsView).
 */
export default function MyCards({
    firstName,
    rewardsReady,
    cards,
    redeemNotices,
}: {
    firstName: string;
    rewardsReady: number;
    cards: MyCard[];
    /** Rewards staff redeemed with a scan that nothing told the customer about yet (CHW-228, 14b). */
    redeemNotices: RedeemNoticeData[];
}) {
    const { t } = useTranslation();
    const { props, version } = usePage();
    const { auth, locale, dir, translations } = props;

    // Kept on the phone for when it is offline (CHW-35), with the words of the customer's language.
    useEffect(() => {
        saveSnapshot(
            snapshotOf(
                {
                    userId: auth.user.id,
                    firstName,
                    rewardsReady,
                    cards,
                    locale,
                    dir,
                },
                new Date(),
            ),
        ).catch(() => undefined);
    }, [auth.user.id, cards, dir, firstName, locale, rewardsReady]);

    useEffect(() => {
        if (translations) {
            saveTranslations({
                locale,
                version: version ?? '',
                strings: translations,
            }).catch(() => undefined);
        }
    }, [locale, translations, version]);

    return (
        <>
            <Head title={t('My cards')} />
            <MyCardsView
                firstName={firstName}
                rewardsReady={rewardsReady}
                cards={cards}
                notices={redeemNotices.map((notice) => (
                    <RedeemNotice key={notice.id} notice={notice} />
                ))}
            />
        </>
    );
}
