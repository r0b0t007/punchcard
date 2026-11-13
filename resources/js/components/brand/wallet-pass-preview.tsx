import { QrCode } from 'lucide-react';
import type { CSSProperties } from 'react';
import {
    gridColumnsClass,
    progressFor,
} from '@/components/loyalty-card/layout';
import BrandMonogram from '@/components/loyalty-card/brand-monogram';
import { useLogo } from '@/components/loyalty-card/use-logo';
import { useTranslation } from '@/hooks/use-translation';
import { cardInks } from '@/lib/color';
import { cn } from '@/lib/utils';

/**
 * A drawing of the Wallet pass front (B17, design 24): the organization's
 * logo and name, the stamps on the strip, the reward and the member QR, in the
 * brand colour. Wallet cards themselves come with CHW-38 and CHW-40; this only
 * shows how the brand will sit on one.
 */
export default function WalletPassPreview({
    name,
    logoUrl,
    brandColor,
    brandForeground,
    stampsRequired,
    stampsCollected,
    rewardText,
}: {
    name: string;
    logoUrl: string | null;
    brandColor: string | null;
    brandForeground: string | null;
    stampsRequired: number;
    stampsCollected: number;
    rewardText: string;
}) {
    const { t } = useTranslation();
    const [logo, logoFailed] = useLogo(logoUrl);
    const { brand, foreground, stamp } = cardInks(brandColor, brandForeground);
    const { required, collected, slots, filled } = progressFor(
        stampsRequired,
        stampsCollected,
    );
    // Runtime colours enter the UI only as CSS variables (design-tokens skill).
    const brandVariables = {
        '--card-brand': brand,
        '--card-brand-foreground': foreground,
        '--card-stamp': stamp,
    } as CSSProperties;

    return (
        <div
            role="img"
            aria-label={t(
                'Wallet pass preview: :name, :collected of :required stamps',
                { name, collected, required },
            )}
            style={brandVariables}
            className="flex flex-col gap-3 rounded-xl bg-card-brand p-3.5 pb-4 text-card-brand-foreground shadow-md"
        >
            <div className="flex items-center gap-2">
                {logo ? (
                    <img
                        src={logo}
                        alt=""
                        onError={logoFailed}
                        className="size-7 shrink-0 rounded-full object-cover"
                    />
                ) : (
                    <BrandMonogram
                        name={name}
                        brandColor={brand}
                        brandForeground={foreground}
                        className="size-7 text-sm ring-1 ring-card-brand-foreground/30"
                    />
                )}
                <span className="min-w-0 flex-1 truncate text-sm font-semibold">
                    {name}
                </span>
                <span className="flex flex-col items-end">
                    <span className="text-2xs tracking-wider uppercase opacity-80">
                        {t('Stamps')}
                    </span>
                    <span className="font-semibold tabular-nums" dir="ltr">
                        {collected}/{required}
                    </span>
                </span>
            </div>
            <div
                aria-hidden="true"
                className={cn(
                    'grid gap-1.5 rounded-md bg-card-brand-foreground/10 p-3',
                    gridColumnsClass(slots),
                )}
            >
                {Array.from({ length: slots }, (_, index) => (
                    <span
                        key={index}
                        className={cn(
                            'aspect-square rounded-full',
                            index < filled
                                ? 'bg-card-stamp'
                                : 'border-2 border-dashed border-card-brand-foreground/45',
                        )}
                    />
                ))}
            </div>
            <div className="flex flex-col">
                <span className="text-2xs tracking-wider uppercase opacity-80">
                    {t('Reward')}
                </span>
                <span className="truncate text-sm font-semibold">
                    {rewardText}
                </span>
            </div>
            <span className="grid size-24 place-items-center self-center rounded-md bg-white text-black">
                <QrCode aria-hidden="true" className="size-16" />
            </span>
        </div>
    );
}
