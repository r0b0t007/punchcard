import type { TapCard } from '@/components/tap/tap-screen';

/** One of the customer's cards on My cards (ListMyCards, CHW-27). */
export type MyCard = {
    /** The enrollment. */
    id: number;
    card: TapCard;
    logoUrl: string | null;
    rewardsReady: number;
    /** Local days since the last stamp in person; null before any. */
    lastVisitDaysAgo: number | null;
    paused: boolean;
};
