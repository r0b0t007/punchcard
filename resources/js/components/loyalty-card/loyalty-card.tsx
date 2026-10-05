import { Gift } from 'lucide-react';
import { monogramOf } from '@/components/loyalty-card/monogram';
import type { CSSProperties } from 'react';
import { useState } from 'react';
import {
    gridColumnsClass,
    progressFor,
} from '@/components/loyalty-card/layout';
import type { StampStyle } from '@/components/loyalty-card/stamp';
import Stamp from '@/components/loyalty-card/stamp';
import { useTranslation } from '@/hooks/use-translation';
import { cardInks } from '@/lib/color';
import { cn } from '@/lib/utils';

export type LoyaltyCardProps = {
    businessName: string;
    /** Optional card name under the business name, e.g. "Coffee card". */
    cardName?: string | null;
    logoUrl?: string | null;
    /** 5 to 50 is the supported range; the grid caps there, the text keeps the real numbers. */
    stampsRequired: number;
    stampsCollected: number;
    stampStyle?: StampStyle;
    /** The organization's brand colour (hex). Defaults to the espresso card. */
    brandColor?: string | null;
    /** Text colour stored by the server for brandColor; ignored when brandColor is missing or invalid. */
    brandForeground?: string | null;
    rewardText: string;
    /** Replaces the progress line, e.g. "Sign in to keep it" on C1. */
    progressLabel?: string;
    /** Shows the next empty slot as the stamp waiting for sign-in (C1). */
    ghostNext?: boolean;
    /** The stamps before the ones that just landed (C2): the slots they filled animate in. */
    landingFrom?: number;
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
    progressLabel,
    ghostNext = false,
    landingFrom,
    className,
}: LoyaltyCardProps) {
    const { t } = useTranslation();
    const { required, collected, remaining, slots, filled } = progressFor(
        stampsRequired,
        stampsCollected,
    );
    // The slots the stamps just given filled (not one slot per stamp: a long card shares slots).
    const filledBefore =
        landingFrom === undefined
            ? filled
            : progressFor(stampsRequired, landingFrom).filled;
    const { brand, foreground, stamp } = cardInks(brandColor, brandForeground);
    // The one place runtime colours enter the UI: CSS variables scoped to this card.
    const brandVariables = {
        '--card-brand': brand,
        '--card-brand-foreground': foreground,
        '--card-stamp': stamp,
    } as CSSProperties;
    // A broken logo falls back to the monogram and plain stamps.
    const [logoFailed, setLogoFailed] = useState(false);
    const logo = logoUrl && !logoFailed ? logoUrl : null;

    return (
        <div
            role="group"
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
                    {logo ? (
                        <img
                            src={logo}
                            alt=""
                            onError={() => setLogoFailed(true)}
                            className="size-full object-cover"
                        />
                    ) : (
                        <span className="font-display text-lg font-bold">
                            {monogramOf(businessName)}
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
                className={cn('grid gap-2', gridColumnsClass(slots))}
            >
                {Array.from({ length: slots }, (_, index) => {
                    const isLanding = index >= filledBefore && index < filled;

                    return (
                        <li key={index} className="relative grid">
                            {isLanding && (
                                <span className="pointer-events-none absolute inset-0 animate-stamp-splash rounded-full border-2 border-card-stamp motion-reduce:hidden" />
                            )}
                            <Stamp
                                filled={index < filled}
                                index={index}
                                stampStyle={stampStyle}
                                logoUrl={logo}
                                onLogoError={() => setLogoFailed(true)}
                                ghost={ghostNext && index === filled}
                                landing={isLanding}
                            />
                        </li>
                    );
                })}
            </ol>

            {/* Two lines so neither the reward nor the progress is clipped on a 320px phone. */}
            <footer className="flex flex-col gap-1 border-t border-card-brand-foreground/20 pt-3 text-sm">
                <p className="flex min-w-0 items-center gap-2 font-medium">
                    <Gift aria-hidden="true" className="size-4 shrink-0" />
                    <span className="min-w-0 truncate">{rewardText}</span>
                </p>
                <p>
                    {progressLabel ??
                        (remaining === 0
                            ? t('Reward ready')
                            : t(
                                  ':count more stamp to your reward|:count more stamps to your reward',
                                  { count: remaining },
                              ))}
                </p>
            </footer>
        </div>
    );
}
