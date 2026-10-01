import { Gift } from 'lucide-react';
import type { CSSProperties } from 'react';
import {
    clampStamps,
    gridColumnsClass,
} from '@/components/loyalty-card/layout';
import type { StampStyle } from '@/components/loyalty-card/stamp';
import Stamp from '@/components/loyalty-card/stamp';
import { useTranslation } from '@/hooks/use-translation';
import { ESPRESSO, isHex, readableForeground, stampColor } from '@/lib/color';
import { cn } from '@/lib/utils';

export type LoyaltyCardProps = {
    businessName: string;
    /** Optional card name under the business name, e.g. "Coffee card". */
    cardName?: string | null;
    logoUrl?: string | null;
    /** 5 to 50; values outside are clamped. */
    stampsRequired: number;
    stampsCollected: number;
    stampStyle?: StampStyle;
    /** The organization's brand colour (hex). Defaults to the espresso card. */
    brandColor?: string | null;
    /** Text colour stored by the server for the brand colour; computed when absent. */
    brandForeground?: string | null;
    rewardText: string;
    className?: string;
};

/**
 * The signature component (design-tokens skill): logo, business name, stamp
 * grid, reward line and progress. The brand colour only ever paints this card
 * (ADR 0007), through the --card-brand variables.
 */
export default function LoyaltyCard({
    businessName,
    cardName,
    logoUrl,
    stampsRequired,
    stampsCollected,
    stampStyle = 'dot',
    brandColor,
    brandForeground,
    rewardText,
    className,
}: LoyaltyCardProps) {
    const { t } = useTranslation();
    const { required, collected } = clampStamps(
        stampsRequired,
        stampsCollected,
    );
    const remaining = required - collected;
    const brand = brandColor && isHex(brandColor) ? brandColor : ESPRESSO;
    const foreground =
        brandForeground && isHex(brandForeground)
            ? brandForeground
            : readableForeground(brand);

    // The one place runtime colours enter the UI: CSS variables scoped to this card.
    const brandVariables = {
        '--card-brand': brand,
        '--card-brand-foreground': foreground,
        '--card-stamp': stampColor(brand),
    } as CSSProperties;

    return (
        <section
            aria-label={t(
                ':business loyalty card, :collected of :required stamps',
                {
                    business: businessName,
                    collected,
                    required,
                },
            )}
            style={brandVariables}
            className={cn(
                'flex flex-col gap-4 overflow-hidden rounded-card bg-card-brand p-5 text-card-brand-foreground shadow-md',
                className,
            )}
        >
            <header className="flex items-center gap-3">
                <span className="grid size-11 shrink-0 place-items-center overflow-hidden rounded-full bg-card-brand-foreground/10 ring-1 ring-card-brand-foreground/25">
                    {logoUrl ? (
                        <img
                            src={logoUrl}
                            alt=""
                            className="size-full object-cover"
                        />
                    ) : (
                        <span className="font-display text-lg font-bold">
                            {businessName.trim().charAt(0).toUpperCase()}
                        </span>
                    )}
                </span>
                <div className="min-w-0 flex-1">
                    <p className="truncate font-display text-xl leading-tight font-bold">
                        {businessName}
                    </p>
                    {cardName && <p className="truncate text-sm">{cardName}</p>}
                </div>
                <p
                    aria-hidden="true"
                    className="shrink-0 font-display text-2xl leading-none font-bold tabular-nums"
                >
                    {collected}/{required}
                </p>
            </header>

            <ol
                aria-hidden="true"
                className={cn('grid gap-2', gridColumnsClass(required))}
            >
                {Array.from({ length: required }, (_, index) => (
                    <li key={index} className="grid">
                        <Stamp
                            filled={index < collected}
                            index={index}
                            stampStyle={stampStyle}
                            logoUrl={logoUrl}
                        />
                    </li>
                ))}
            </ol>

            <footer className="flex items-center gap-2 border-t border-card-brand-foreground/20 pt-3 text-sm">
                <Gift aria-hidden="true" className="size-4 shrink-0" />
                <p className="min-w-0 flex-1 truncate font-medium">
                    {rewardText}
                </p>
                <p className="shrink-0">
                    {remaining === 0
                        ? t('Reward ready')
                        : t(
                              ':count more stamp to your reward|:count more stamps to your reward',
                              { count: remaining },
                          )}
                </p>
            </footer>
        </section>
    );
}
