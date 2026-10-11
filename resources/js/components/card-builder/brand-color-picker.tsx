import type { CSSProperties } from 'react';
import { useState } from 'react';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useTranslation } from '@/hooks/use-translation';
import { BRAND_SWATCHES, ESPRESSO } from '@/lib/color';
import { cn } from '@/lib/utils';

const HEX = /^#[0-9a-f]{6}$/i;

/**
 * The card's colour (B6): the organization's (null, "Default"), one of the
 * 8 swatches, or a custom hex. The colour only paints the card (ADR 0007).
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
    const isSwatch = (hex: string | null) =>
        BRAND_SWATCHES.some((swatch) => swatch.hex === hex?.toUpperCase());
    // What the owner typed, as typed; the card takes it once it is a whole hex colour.
    const [custom, setCustom] = useState(
        value !== null && !isSwatch(value) ? value : '',
    );
    const options = [
        {
            label: t('Default'),
            hex: organizationColor ?? ESPRESSO,
            value: null,
        },
        ...BRAND_SWATCHES.map((swatch) => ({
            label: t(swatch.label),
            hex: swatch.hex,
            value: swatch.hex,
        })),
    ];
    const selected = value?.toUpperCase() ?? null;

    return (
        <div className="flex flex-col gap-3">
            <div className="grid max-w-sm grid-cols-9 gap-1.5">
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
                            'aspect-square w-full rounded-full border bg-card-brand ring-offset-2 ring-offset-background outline-none focus-visible:ring-2 focus-visible:ring-ring',
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
                        value={(value ?? options[0].hex).toLowerCase()}
                        onChange={(event) => {
                            const hex = event.target.value.toUpperCase();
                            setCustom(hex);
                            onChange(hex);
                        }}
                        className="h-11 w-12 shrink-0 cursor-pointer rounded-button border bg-transparent p-1"
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

                            if (HEX.test(typed)) {
                                onChange(typed.toUpperCase());
                            }
                        }}
                    />
                </div>
            </div>
        </div>
    );
}
