import type { ReactNode } from 'react';
import LoyaltyCard from '@/components/loyalty-card';
import type { LoyaltyCardProps, StampStyle } from '@/components/loyalty-card';
import BrandMonogram from '@/components/loyalty-card/brand-monogram';
import { cn } from '@/lib/utils';

/** The café's card as a tap result shows it (DescribeTap). */
export type TapCard = {
    businessName: string;
    locationName: string | null;
    cardName: string | null;
    stampsRequired: number;
    stampsCollected: number;
    rewardText: string;
    brandColor: string | null;
    /** The text colour the card stores for its own brand colour (CHW-32). */
    brandForeground?: string | null;
    stampStyle: StampStyle | null;
};

/**
 * The frame of the tap result screens (C1, C2, cooldown, refused; Claude
 * Design "Punchcard Tap Flow"): content from the top, one primary action at
 * thumb height. The café's brand colour reaches only the card and the café's
 * monogram (ADR 0007).
 */
export default function TapScreen({
    children,
    actions,
    className,
}: {
    children: ReactNode;
    actions?: ReactNode;
    /** E.g. min-h-0 inside the customer layout, which already fills the screen (CHW-27). */
    className?: string;
}) {
    return (
        <main
            className={cn(
                'mx-auto flex min-h-dvh w-full max-w-md flex-col bg-background text-foreground',
                className,
            )}
        >
            <div className="flex flex-col gap-7 px-6 pt-5">{children}</div>
            {actions ? (
                <div className="mt-auto flex flex-col gap-3 px-6 pt-8 pb-8">
                    {actions}
                </div>
            ) : null}
        </main>
    );
}

/** The café the tap happened at: its monogram in the brand colour, name and location (C1). */
export function TapCafe({ card }: { card: TapCard }) {
    return (
        <div className="flex items-center gap-3">
            <BrandMonogram
                name={card.businessName}
                brandColor={card.brandColor}
                brandForeground={card.brandForeground}
                className="size-13 text-3xl ring-1 ring-border"
            />
            <div className="min-w-0">
                <p className="truncate text-lg font-semibold">
                    {card.businessName}
                </p>
                {card.locationName ? (
                    <p className="truncate text-muted-foreground">
                        {card.locationName}
                    </p>
                ) : null}
            </div>
        </div>
    );
}

/** LoyaltyCard for a tap result's card. */
export function TapLoyaltyCard({
    card,
    ...states
}: { card: TapCard } & Pick<
    LoyaltyCardProps,
    'logoUrl' | 'progressLabel' | 'ghostNext' | 'landingFrom'
>) {
    return (
        <LoyaltyCard
            businessName={card.businessName}
            cardName={card.cardName}
            stampsRequired={card.stampsRequired}
            stampsCollected={card.stampsCollected}
            rewardText={card.rewardText}
            brandColor={card.brandColor}
            brandForeground={card.brandForeground}
            stampStyle={card.stampStyle ?? undefined}
            {...states}
        />
    );
}
