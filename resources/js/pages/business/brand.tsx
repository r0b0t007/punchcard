import { Head, router, useForm } from '@inertiajs/react';
import { Check, Trash2, TriangleAlert } from 'lucide-react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import BrandImageUpload from '@/components/brand/brand-image-upload';
import WalletPassPreview from '@/components/brand/wallet-pass-preview';
import BrandColorPicker from '@/components/card-builder/brand-color-picker';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import LoyaltyCard from '@/components/loyalty-card';
import type { StampStyle } from '@/components/loyalty-card';
import BrandMonogram from '@/components/loyalty-card/brand-monogram';
import { TapCafe } from '@/components/tap/tap-screen';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';
import { Spinner } from '@/components/ui/spinner';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { useTranslation } from '@/hooks/use-translation';
import { suggestedShade } from '@/lib/color';
import { cover, edit, logo, update } from '@/routes/brand';
import { destroy as removeCover } from '@/routes/brand/cover';

type Brand = {
    /** As customers see it: the business's name inside an independent café or chain, else the organization's. */
    name: string;
    franchise: boolean;
    logoUrl: string | null;
    coverUrl: string | null;
    brandColor: string | null;
    /** The text colour the server works out for brandColor (Organization::brandForeground). */
    brandForeground: string | null;
};

type Sample = {
    cardName: string;
    rewardText: string;
    stampsRequired: number;
    stampStyle: StampStyle | null;
};

type Preview = 'card' | 'tap' | 'pass';

/**
 * B17 (CHW-135, design 24): the organization's logo, brand colour and cover,
 * with previews of the loyalty card, the tap screen and the Wallet pass. The
 * logo and cover save as soon as they are picked; the colour with Save. When
 * no text reads on the colour, a nearby shade that does is one tap away.
 */
export default function BrandPage({
    brand,
    sample,
    sites,
}: {
    brand: Brand;
    sample: Sample | null;
    /** A franchise's open sites that show the brand; null otherwise. */
    sites: number | null;
}) {
    const { t } = useTranslation();
    const { data, setData, setDefaults, patch, processing, errors, isDirty } =
        useForm<{
            brand_color: string | null;
        }>({ brand_color: brand.brandColor });
    const [preview, setPreview] = useState<Preview>('card');
    const [removing, setRemoving] = useState(false);
    const color = data.brand_color;
    const shade = color ? suggestedShade(color) : null;
    // The server's text colour while the saved colour shows; the card works out its own for a new one.
    const foreground =
        color === brand.brandColor ? brand.brandForeground : null;
    const stampsRequired = sample?.stampsRequired ?? 10;
    const card = {
        businessName: brand.name,
        cardName: sample?.cardName ?? t('Coffee card'),
        stampsRequired,
        stampsCollected: Math.max(1, stampsRequired - 3),
        rewardText: sample?.rewardText ?? t('A free coffee'),
        stampStyle: sample?.stampStyle ?? null,
        brandColor: color,
        brandForeground: foreground,
    };

    const previews: Record<Preview, ReactNode> = {
        card: (
            <LoyaltyCard
                {...card}
                stampStyle={card.stampStyle ?? undefined}
                logoUrl={brand.logoUrl}
            />
        ),
        tap: (
            <div className="overflow-hidden rounded-3xl border bg-background">
                {brand.coverUrl ? (
                    <img
                        src={brand.coverUrl}
                        alt=""
                        className="h-24 w-full object-cover"
                    />
                ) : null}
                <div className="flex flex-col gap-3 p-4">
                    <TapCafe card={{ ...card, locationName: null }} />
                    <p className="font-display text-3xl leading-none font-extrabold">
                        {t(':count stamp added|:count stamps added', {
                            count: 1,
                        })}
                    </p>
                </div>
            </div>
        ),
        pass: (
            <div className="flex flex-col gap-2">
                <WalletPassPreview
                    name={brand.name}
                    logoUrl={brand.logoUrl}
                    brandColor={color}
                    brandForeground={foreground}
                    stampsRequired={card.stampsRequired}
                    stampsCollected={card.stampsCollected}
                    rewardText={card.rewardText}
                />
                <p className="text-xs text-muted-foreground">
                    {t(
                        'How your Wallet pass will look once Wallet cards arrive.',
                    )}
                </p>
            </div>
        ),
    };
    const labels: Record<Preview, string> = {
        card: t('Loyalty card'),
        tap: t('Tap screen'),
        pass: t('Wallet pass'),
    };

    return (
        <>
            <Head title={t('Brand')} />
            <div className="flex flex-col gap-10 p-4 lg:flex-row lg:items-start">
                <div className="flex max-w-xl min-w-0 flex-1 flex-col gap-8">
                    <Heading
                        variant="small"
                        title={t('Brand')}
                        description={
                            brand.franchise && sites !== null
                                ? t(
                                      ":name's logo, colour and cover, shown at your :count site: on the card, the tap screens and the Wallet pass.|:name's logo, colour and cover, shown at your :count sites: on the card, the tap screens and the Wallet pass.",
                                      { name: brand.name, count: sites },
                                  )
                                : t(
                                      'Your logo, colour and cover on your cards, tap screens and Wallet pass. The app itself stays punchcard.',
                                  )
                        }
                    />

                    <section
                        aria-labelledby="brand-logo"
                        className="flex flex-col gap-3"
                    >
                        <h2 id="brand-logo" className="font-semibold">
                            {t('Logo')}
                        </h2>
                        <div className="flex items-center gap-4">
                            {brand.logoUrl ? (
                                <img
                                    src={brand.logoUrl}
                                    alt=""
                                    className="size-16 shrink-0 rounded-full object-cover ring-1 ring-border"
                                />
                            ) : (
                                <BrandMonogram
                                    name={brand.name}
                                    brandColor={color}
                                    brandForeground={foreground}
                                    className="size-16 text-2xl"
                                />
                            )}
                            <div className="flex flex-col gap-2">
                                <p className="text-sm text-muted-foreground">
                                    {t(
                                        'Square works best, at least 128 px. JPEG, PNG or WebP, up to 2 MB.',
                                    )}
                                </p>
                                <BrandImageUpload
                                    field="logo"
                                    url={logo.url()}
                                    label={
                                        brand.logoUrl
                                            ? t('Change logo')
                                            : t('Add a logo')
                                    }
                                />
                            </div>
                        </div>
                    </section>

                    <Separator />

                    <form
                        aria-labelledby="brand-colour"
                        className="flex flex-col gap-3"
                        onSubmit={(event) => {
                            event.preventDefault();
                            patch(update.url(), {
                                preserveScroll: true,
                                // The saved colour is the one to compare with now: Save waits for the next change.
                                onSuccess: () => setDefaults(),
                            });
                        }}
                    >
                        <h2 id="brand-colour" className="font-semibold">
                            {t('Brand colour')}
                        </h2>
                        <p className="text-sm text-muted-foreground">
                            {t(
                                'Paints your cards, tap screens and Wallet pass.',
                            )}
                        </p>
                        <BrandColorPicker
                            value={color}
                            organizationColor={null}
                            onChange={(value) => setData('brand_color', value)}
                        />
                        {shade ? (
                            <Alert data-test="weak-colour">
                                <TriangleAlert />
                                <AlertDescription>
                                    <span>
                                        {t(
                                            'Text is hard to read on this colour. Customers would struggle with their stamps and reward. This nearby shade reads clearly:',
                                        )}
                                    </span>
                                    <span className="mt-2 flex items-center gap-3">
                                        <BrandMonogram
                                            name=""
                                            brandColor={shade}
                                            className="size-6"
                                        />
                                        <span
                                            dir="ltr"
                                            className="font-semibold text-foreground"
                                        >
                                            {shade}
                                        </span>
                                        <Button
                                            type="button"
                                            size="sm"
                                            variant="outline"
                                            onClick={() =>
                                                setData('brand_color', shade)
                                            }
                                        >
                                            {t('Use this shade')}
                                        </Button>
                                    </span>
                                </AlertDescription>
                            </Alert>
                        ) : color ? (
                            <p className="flex items-center gap-1.5 text-sm text-success">
                                <Check aria-hidden="true" className="size-4" />
                                {t('Text reads clearly on this colour.')}
                            </p>
                        ) : null}
                        <InputError message={errors.brand_color} />

                        <div className="flex flex-col gap-3 lg:hidden">
                            <ToggleGroup
                                type="single"
                                variant="segmented"
                                aria-label={t('Preview')}
                                className="self-start"
                                value={preview}
                                onValueChange={(value) =>
                                    value && setPreview(value as Preview)
                                }
                            >
                                {(Object.keys(labels) as Preview[]).map(
                                    (key) => (
                                        <ToggleGroupItem key={key} value={key}>
                                            {labels[key]}
                                        </ToggleGroupItem>
                                    ),
                                )}
                            </ToggleGroup>
                            {previews[preview]}
                        </div>

                        <div className="flex flex-wrap items-center gap-3">
                            <Button
                                type="submit"
                                disabled={processing || !isDirty}
                                data-test="save-brand-colour"
                            >
                                {processing && <Spinner />}
                                {t('Save')}
                            </Button>
                            <span className="text-sm text-muted-foreground">
                                {t('Customers see it on their next visit.')}
                            </span>
                        </div>
                    </form>

                    <Separator />

                    <section
                        aria-labelledby="brand-cover"
                        className="flex flex-col gap-3"
                    >
                        <h2 id="brand-cover" className="font-semibold">
                            {t('Cover')}
                        </h2>
                        <p className="text-sm text-muted-foreground">
                            {t(
                                'A wide photo at the top of the tap screens and the card page. At least 600 × 200 px.',
                            )}
                        </p>
                        {brand.coverUrl ? (
                            <img
                                src={brand.coverUrl}
                                alt=""
                                className="h-32 w-full rounded-xl object-cover"
                            />
                        ) : null}
                        <div className="flex flex-wrap gap-2">
                            <BrandImageUpload
                                field="cover"
                                url={cover.url()}
                                label={
                                    brand.coverUrl
                                        ? t('Change cover')
                                        : t('Add a cover photo')
                                }
                            />
                            {brand.coverUrl ? (
                                <Button
                                    type="button"
                                    variant="outline"
                                    disabled={removing}
                                    onClick={() =>
                                        router.delete(removeCover.url(), {
                                            preserveScroll: true,
                                            // A colour picked but not saved yet stays picked, as with the uploads.
                                            preserveState: true,
                                            onStart: () => setRemoving(true),
                                            onFinish: () => setRemoving(false),
                                        })
                                    }
                                    data-test="remove-cover"
                                >
                                    {removing ? (
                                        <Spinner />
                                    ) : (
                                        <Trash2 aria-hidden="true" />
                                    )}
                                    {t('Remove')}
                                </Button>
                            ) : null}
                        </div>
                    </section>
                </div>

                <aside
                    aria-label={t('Preview')}
                    className="hidden w-88 shrink-0 flex-col gap-4 lg:sticky lg:top-4 lg:flex"
                >
                    {(Object.keys(labels) as Preview[]).map((key) => (
                        <div key={key} className="flex flex-col gap-2">
                            <h2 className="text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                                {labels[key]}
                            </h2>
                            {previews[key]}
                        </div>
                    ))}
                </aside>
            </div>
        </>
    );
}

BrandPage.layout = {
    breadcrumbs: [
        {
            title: 'Brand',
            href: edit(),
        },
    ],
};
