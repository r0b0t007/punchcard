import type { CSSProperties } from 'react';
import { useState } from 'react';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useTranslation } from '@/hooks/use-translation';
import { BRAND_SWATCHES, ESPRESSO, isHex } from '@/lib/color';
import { cn } from '@/lib/utils';

/**
 * A colour (ADR 0007): "Default" (null: the organization's on a card, B6;
 * punchcard's espresso on the brand page, B17), one of the swatches, or a
 * custom hex. With no organization colour, "Default" already is espresso, so
 * the Espresso swatch is left out rather than shown twice.
 */
export default function BrandColorPicker({
    value,
    organizationColor,
    onChange,
}: {
    value: string | null;
    organizationColor: string | null;
    onChange: (value: string | null) => void;
}) {
    const { t } = useTranslation();
    const swatches = BRAND_SWATCHES.filter(
        (swatch) => organizationColor !== null || swatch.hex !== ESPRESSO,
    );
    const isSwatch = (hex: string | null) =>
        swatches.some((swatch) => swatch.hex === hex?.toUpperCase());
    // What the owner typed, as typed; the card takes it once it is a whole hex colour.
    const [custom, setCustom] = useState(
        value !== null && !isSwatch(value) ? value : '',
    );
    // A colour set from outside (B17's "Use this shade"): the field shows it, as if typed.
    const [shown, setShown] = useState(value);

    if (value !== shown) {
        setShown(value);

        if (value === null || isSwatch(value)) {
            setCustom('');
        } else if (custom.toUpperCase() !== value.toUpperCase()) {
            setCustom(value);
        }
    }

    const options = [
        {
            label: t('Default'),
            hex: organizationColor ?? ESPRESSO,
            value: null as string | null,
        },
        ...swatches.map((swatch) => ({
            label: t(swatch.label),
            hex: swatch.hex,
            value: swatch.hex,
        })),
    ];
    const selected = value?.toUpperCase() ?? null;

    return (
        <div className="flex flex-col gap-3">
            {/* 30px swatches (BA Screen.dc.html, .sw), however wide the form. */}
            <div className="flex max-w-sm flex-wrap gap-2.5">
                {options.map((option) => (
                    <button
                        key={option.label}
                        type="button"
                        aria-label={option.label}
                        aria-pressed={selected === option.value}
                        title={option.label}
                        onClick={() => {
                            setCustom('');
                            onChange(option.value);
                        }}
                        // Runtime colours enter the UI only as CSS variables (design-tokens skill).
                        style={{ '--card-brand': option.hex } as CSSProperties}
                        className={cn(
                            'size-7.5 shrink-0 rounded-full border bg-card-brand ring-offset-2 ring-offset-background outline-none focus-visible:ring-2 focus-visible:ring-ring',
                            selected === option.value &&
                                'ring-2 ring-foreground',
                        )}
                    />
                ))}
            </div>
            <div className="grid gap-2">
                <Label htmlFor="brand_color_custom">{t('Custom colour')}</Label>
                <div className="flex gap-2">
                    <input
                        type="color"
                        aria-label={t('Pick a colour')}
                        value={(
                            value ??
                            organizationColor ??
                            ESPRESSO
                        ).toLowerCase()}
                        onChange={(event) => {
                            const hex = event.target.value.toUpperCase();
                            setCustom(hex);
                            onChange(hex);
                        }}
                        className="h-12 w-12 shrink-0 cursor-pointer rounded-md border bg-transparent p-1"
                    />
                    <Input
                        id="brand_color_custom"
                        dir="ltr"
                        placeholder="#RRGGBB"
                        maxLength={7}
                        value={custom}
                        onChange={(event) => {
                            const typed = event.target.value.trim();
                            setCustom(typed);

                            // #rrggbb only, as the server takes it.
                            if (isHex(typed) && typed.length === 7) {
                                onChange(typed.toUpperCase());
                            }
                        }}
                    />
                </div>
            </div>
        </div>
    );
}
