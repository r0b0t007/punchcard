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
 * ink-stamp marks in the card's stamp colour, slightly rotated per position.
 */
export default function Stamp({
    filled,
    index,
    stampStyle,
    logoUrl,
}: {
    filled: boolean;
    index: number;
    stampStyle: StampStyle;
    logoUrl?: string | null;
}) {
    if (!filled) {
        return (
            <span className="aspect-square rounded-full border-2 border-dashed border-card-brand-foreground/40" />
        );
    }

    const mark = cn(
        'grid aspect-square place-items-center rounded-full',
        rotationClass(index),
    );

    if (stampStyle === 'ring') {
        return <span className={cn(mark, 'border-4 border-card-stamp')} />;
    }

    if (stampStyle === 'logo' && logoUrl) {
        return (
            <span className={cn(mark, 'overflow-hidden bg-card-stamp p-0.5')}>
                <img
                    src={logoUrl}
                    alt=""
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
            <span className={cn(mark, 'bg-card-stamp text-card-brand')}>
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
