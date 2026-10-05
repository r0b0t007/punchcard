import { Check, Heart, Star } from 'lucide-react';
import { rotationClass } from '@/components/loyalty-card/layout';
import { cn } from '@/lib/utils';

export const STAMP_STYLES = [
    'dot',
    'ring',
    'check',
    'heart',
    'star',
    'logo',
] as const;

export type StampStyle = (typeof STAMP_STYLES)[number];

const ICONS = { check: Check, heart: Heart, star: Star };

/**
 * One slot of the stamp grid. Empty slots are dashed outlines; filled ones are
 * ink-stamp marks in the card's stamp colour. Icon and logo stamps sit slightly
 * askew, per position; round dots and rings would not show a rotation.
 *
 * - ghost: the stamp waiting for sign-in (C1), a faint mark in a dashed ring.
 * - landing: a stamp just given (C2), pressed onto the card.
 */
export default function Stamp({
    filled,
    index,
    stampStyle,
    logoUrl,
    onLogoError,
    ghost = false,
    landing = false,
}: {
    filled: boolean;
    index: number;
    stampStyle: StampStyle;
    logoUrl?: string | null;
    onLogoError?: () => void;
    ghost?: boolean;
    landing?: boolean;
}) {
    if (!filled && ghost) {
        return (
            <span className="grid aspect-square place-items-center rounded-full border-2 border-dashed border-card-stamp bg-card-stamp/15 text-card-stamp">
                <Star className="size-2/5 opacity-60" fill="currentColor" />
            </span>
        );
    }

    if (!filled) {
        return (
            <span className="aspect-square rounded-full border-2 border-dashed border-card-brand-foreground/80" />
        );
    }

    const mark = cn(
        'grid aspect-square place-items-center rounded-full',
        landing && 'animate-stamp-land motion-reduce:animate-none',
    );
    const askew = cn(mark, rotationClass(index));

    if (stampStyle === 'ring') {
        return <span className={cn(mark, 'border-4 border-card-stamp')} />;
    }

    if (stampStyle === 'logo' && logoUrl) {
        return (
            <span className={cn(askew, 'overflow-hidden bg-card-stamp p-0.5')}>
                <img
                    src={logoUrl}
                    alt=""
                    onError={onLogoError}
                    className="size-full rounded-full object-cover"
                />
            </span>
        );
    }

    if (
        stampStyle === 'check' ||
        stampStyle === 'heart' ||
        stampStyle === 'star'
    ) {
        const Icon = ICONS[stampStyle];

        return (
            <span className={cn(askew, 'bg-card-stamp text-card-brand')}>
                <Icon
                    className="size-3/5"
                    strokeWidth={3}
                    fill={stampStyle === 'check' ? 'none' : 'currentColor'}
                />
            </span>
        );
    }

    // dot, and logo without an image: a solid mark with a faint inner ring for the inked look.
    return (
        <span
            className={cn(
                mark,
                'bg-card-stamp ring-2 ring-card-brand/15 ring-inset',
            )}
        />
    );
}
