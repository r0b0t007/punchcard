import type { CSSProperties, ReactNode } from 'react';
import LoyaltyCard from '@/components/loyalty-card';
import type { LoyaltyCardProps, StampStyle } from '@/components/loyalty-card';
import { cardInks } from '@/lib/color';

/** The café's card as a tap result shows it (DescribeTap). */
export type TapCard = {
    businessName: string;
    locationName: string | null;
    cardName: string | null;
    stampsRequired: number;
    stampsCollected: number;
    rewardText: string;
    brandColor: string | null;
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
}: {
    children: ReactNode;
    actions?: ReactNode;
}) {
    return (
        <main className="mx-auto flex min-h-dvh w-full max-w-md flex-col bg-background text-foreground">
            <div className="flex flex-col gap-7 px-6 pt-8">{children}</div>
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
    const { brand, foreground } = cardInks(card.brandColor);
    const brandVariables = {
        '--card-brand': brand,
        '--card-brand-foreground': foreground,
    } as CSSProperties;

    return (
        <div className="flex items-center gap-3">
            <span
                aria-hidden="true"
                style={brandVariables}
                className="grid size-13 shrink-0 place-items-center rounded-full bg-card-brand font-display text-3xl font-bold text-card-brand-foreground ring-1 ring-border"
            >
                {(Array.from(card.businessName.trim())[0] ?? '').toUpperCase()}
            </span>
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
    'progressLabel' | 'ghostNext' | 'landing'
>) {
    return (
        <LoyaltyCard
            businessName={card.businessName}
            cardName={card.cardName}
            stampsRequired={card.stampsRequired}
            stampsCollected={card.stampsCollected}
            rewardText={card.rewardText}
            brandColor={card.brandColor}
            stampStyle={card.stampStyle ?? undefined}
            {...states}
        />
    );
}
