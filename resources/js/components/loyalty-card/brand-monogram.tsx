import type { CSSProperties } from 'react';
import { cardInks } from '@/lib/color';
import { cn } from '@/lib/utils';
import { monogramOf } from './monogram';

/**
 * The café's monogram on its brand colour (ADR 0007: the brand colour reaches
 * the card and the café's own mark only), with a readable ink. Size and type
 * come from the caller (className).
 */
export default function BrandMonogram({
    name,
    brandColor,
    className,
}: {
    name: string;
    brandColor: string | null;
    className?: string;
}) {
    const { brand, foreground } = cardInks(brandColor);
    // Runtime colours enter the UI only as CSS variables (design-tokens skill).
    const brandVariables = {
        '--card-brand': brand,
        '--card-brand-foreground': foreground,
    } as CSSProperties;

    return (
        <span
            aria-hidden="true"
            style={brandVariables}
            className={cn(
                'grid shrink-0 place-items-center rounded-full bg-card-brand font-display font-bold text-card-brand-foreground',
                className,
            )}
        >
            {monogramOf(name)}
        </span>
    );
}
