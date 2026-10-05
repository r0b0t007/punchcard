import type { ReactNode } from 'react';
import LoyaltyCard from '@/components/loyalty-card';
import type { StampStyle } from '@/components/loyalty-card';

/** The café's card as a tap result shows it (DescribeTap). */
export type TapCard = {
    businessName: string;
    cardName: string | null;
    stampsRequired: number;
    stampsCollected: number;
    rewardText: string;
    brandColor: string | null;
    stampStyle: StampStyle | null;
};

/**
 * The frame of the tap result screens (C1, C2, cooldown, refused): one
 * column, the café's card, the message, actions at thumb height. The café's
 * brand colour only reaches the card (ADR 0007).
 */
export default function TapScreen({
    title,
    card,
    children,
}: {
    title: string;
    card?: TapCard | null;
    children?: ReactNode;
}) {
    return (
        <main className="mx-auto flex min-h-dvh w-full max-w-md flex-col gap-6 bg-background px-4 py-8 text-foreground">
            {card ? (
                <p className="text-sm text-muted-foreground">
                    {card.businessName}
                </p>
            ) : null}
            <h1 className="font-display text-3xl font-bold">{title}</h1>
            {card ? (
                <LoyaltyCard
                    businessName={card.businessName}
                    cardName={card.cardName}
                    stampsRequired={card.stampsRequired}
                    stampsCollected={card.stampsCollected}
                    rewardText={card.rewardText}
                    brandColor={card.brandColor}
                    stampStyle={card.stampStyle ?? undefined}
                />
            ) : null}
            <div className="mt-auto flex flex-col gap-3">{children}</div>
        </main>
    );
}
