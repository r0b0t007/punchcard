import type { TapCard } from '@/components/tap/tap-screen';

/** One of the customer's cards on My cards (ListMyCards, CHW-27). */
export type MyCard = {
    /** The enrollment. */
    id: number;
    card: TapCard;
    logoUrl: string | null;
    rewardsReady: number;
    /**
     * Stamps left to the next reward: a progressive card's next tier, null
     * once every tier is earned (CHW-217). Missing from a copy kept offline
     * before it was added.
     */
    stampsToReward?: number | null;
    /** Local days since the last stamp in person; null before any. */
    lastVisitDaysAgo: number | null;
    paused: boolean;
};
