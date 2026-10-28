import { Head, Link, setLayoutProps, useForm } from '@inertiajs/react';
import { Info, Minus, Plus, Trash2, TriangleAlert } from 'lucide-react';
import type { FormEvent } from 'react';
import BannerUpload from '@/components/card-builder/banner-upload';
import BrandColorPicker from '@/components/card-builder/brand-color-picker';
import CardPreview from '@/components/card-builder/card-preview';
import type { CardInfo } from '@/components/card-builder/card-preview';
import TierEditor from '@/components/card-builder/tier-editor';
import type { TierField } from '@/components/card-builder/tier-editor';
import InputError from '@/components/input-error';
import type { StampStyle } from '@/components/loyalty-card';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Separator } from '@/components/ui/separator';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { useTranslation } from '@/hooks/use-translation';
import { ESPRESSO, isWeakBrand } from '@/lib/color';
import { typedDir } from '@/lib/utils';
import { create, edit, index, store, update } from '@/routes/cards';

type CardMode = 'cyclic' | 'progressive';
type RewardType = 'item' | 'percent' | 'fixed';

/** DescribeCardBuilder's fields, CardRequest's names. */
type CardFields = {
    name: string;
    mode: CardMode;
    stamps_required: number;
    reward_type: RewardType;
    /** A percent, or an amount in centimes. */
    reward_value: number | null;
    reward_text: string;
    tiers: { stamps: number; reward: string }[];
    brand_color: string | null;
    stamp_style: StampStyle;
    info: CardInfo;
    terms: string;
    cooldown_min: number;
    daily_cap: number | null;
};

type Props = {
    card: {
        id: number;
        active: boolean;
        held: boolean;
        bannerUrl: string | null;
    } | null;
    fields: CardFields;
    preview: {
        businessName: string;
        logoUrl: string | null;
        organizationColor: string | null;
    };
};

/** Every stamp style (STAMP_STYLES), with its English translation key. */
const STAMP_STYLE_OPTIONS: { value: StampStyle; label: string }[] = [
    { value: 'dot', label: 'Dot' },
    { value: 'ring', label: 'Ring' },
    { value: 'check', label: 'Check' },
    { value: 'heart', label: 'Heart' },
    { value: 'star', label: 'Star' },
    { value: 'logo', label: 'Logo' },
];

/** A whole number typed in a field, or the fallback while it isn't one. */
const whole = (typed: string, fallback: number) => {
    const value = Number.parseInt(typed, 10);

    return Number.isNaN(value) ? fallback : value;
};

/** An amount typed in dirhams ("12", "12.5" or "12,50"), in centimes; anything else as typed, for the server to refuse. */
const centimes = (typed: string) => {
    const amount = typed.trim();

    return /^\d+(?:[.,]\d{1,2})?$/.test(amount)
        ? String(Math.round(Number(amount.replace(',', '.')) * 100))
        : typed;
};

/** The reward value the server stores for what is typed: none for a free item, centimes for an amount, else the percent. */
const storedRewardValue = (type: RewardType, typed: string) =>
    type === 'item' ? null : type === 'fixed' ? centimes(typed) : typed.trim();

/**
 * B6: the card builder. The form, and a live preview of the card as
 * customers will see it (on a phone, above the form; on a wide screen, at
 * its end). Once customers hold the card, its mode and tiers are fixed and
 * its stamps only go down. "Save draft" keeps a card paused, "Publish" runs
 * it; a running card only saves.
 */
export default function CardsEdit({ card, fields, preview }: Props) {
    const { t } = useTranslation();
    const held = card?.held ?? false;
    // A fixed amount shows in dirhams; the server stores centimes.
    const rewardValue =
        fields.reward_value === null
            ? ''
            : String(
                  fields.reward_type === 'fixed'
                      ? fields.reward_value / 100
                      : fields.reward_value,
              );
    // Numbers stay as typed; the preview reads whole numbers, the server checks them.
    const form = useForm({
        name: fields.name,
        mode: fields.mode,
        stamps_required: String(fields.stamps_required),
        reward_type: fields.reward_type,
        reward_value: rewardValue,
        reward_text: fields.reward_text,
        tiers: fields.tiers.map((tier): TierField => ({
            stamps: String(tier.stamps),
            reward: tier.reward,
        })),
        brand_color: fields.brand_color,
        stamp_style: fields.stamp_style,
        info: fields.info,
        terms: fields.terms,
        cooldown_min: String(fields.cooldown_min),
        daily_cap: fields.daily_cap === null ? '' : String(fields.daily_cap),
    });
    const { data, setData, processing } = form;
    const errors = form.errors as Record<string, string | undefined>;

    setLayoutProps({
        breadcrumbs: [
            { title: 'Cards', href: index() },
            card
                ? { title: 'Edit card', href: edit(card.id) }
                : { title: 'New card', href: create() },
        ],
    });

    const progressive = data.mode === 'progressive';
    const tiers = data.tiers
        .map((tier) => ({ stamps: whole(tier.stamps, 0), reward: tier.reward }))
        .filter((tier) => tier.stamps > 0)
        .sort((a, b) => a.stamps - b.stamps);
    const stamps = whole(data.stamps_required, fields.stamps_required);
    const maxStamps = held ? fields.stamps_required : 50;
    const previewStamps = Math.min(
        Math.max(progressive ? (tiers.at(-1)?.stamps ?? 10) : stamps, 5),
        50,
    );
    const previewReward = progressive
        ? tiers.at(-1)?.reward || t('Your reward')
        : data.reward_text || t('Your reward');
    const brand = data.brand_color ?? preview.organizationColor ?? ESPRESSO;
    const rewardChanged =
        held &&
        !progressive &&
        (data.reward_type !== fields.reward_type ||
            data.reward_text.trim() !== fields.reward_text ||
            storedRewardValue(data.reward_type, data.reward_value) !==
                (fields.reward_value === null
                    ? null
                    : String(fields.reward_value)));

    const save = (publish: boolean) => {
        form.transform((typed) => ({
            ...typed,
            reward_value: storedRewardValue(
                typed.reward_type,
                typed.reward_value,
            ),
            publish,
        }));
        form.submit(card ? update(card.id) : store(), { preserveScroll: true });
    };

    const changeMode = (mode: CardMode) => {
        setData((current) => ({
            ...current,
            mode,
            // A first tier from the cyclic card, so the preview has something to show.
            tiers:
                mode === 'progressive' && current.tiers.length === 0
                    ? [
                          {
                              stamps: current.stamps_required,
                              reward: current.reward_text,
                          },
                      ]
                    : current.tiers,
        }));
    };

    const setInfo = (info: Partial<CardInfo>) =>
        setData('info', { ...data.info, ...info });
    const changeLink = (at: number, link: Partial<CardInfo['links'][number]>) =>
        setInfo({
            links: data.info.links.map((current, i) =>
                i === at ? { ...current, ...link } : current,
            ),
        });

    return (
        <>
            <Head title={card ? t('Edit card') : t('New card')} />
            <div className="flex flex-col gap-8 p-4 lg:flex-row-reverse lg:items-start">
                <div className="lg:sticky lg:top-4 lg:w-96 lg:shrink-0">
                    <CardPreview
                        businessName={preview.businessName}
                        cardName={data.name}
                        logoUrl={preview.logoUrl}
                        bannerUrl={card?.bannerUrl ?? null}
                        brandColor={brand}
                        stampsRequired={previewStamps}
                        stampStyle={data.stamp_style}
                        rewardText={previewReward}
                        info={data.info}
                        terms={data.terms}
                    />
                </div>

                <form
                    onSubmit={(event: FormEvent<HTMLFormElement>) => {
                        event.preventDefault();
                        // Both buttons submit, so the browser checks the fields first; Publish says so.
                        const submitter = (event.nativeEvent as SubmitEvent)
                            .submitter;
                        save(submitter?.dataset.publish === 'true');
                    }}
                    className="flex min-w-0 flex-1 flex-col gap-8"
                >
                    <section className="flex flex-col gap-6">
                        <div className="grid gap-2">
                            <Label htmlFor="name">{t('Card name')}</Label>
                            <Input
                                id="name"
                                dir={typedDir(data.name)}
                                required
                                maxLength={80}
                                placeholder={t('Coffee card')}
                                value={data.name}
                                onChange={(event) =>
                                    setData('name', event.target.value)
                                }
                            />
                            <InputError message={errors.name} />
                        </div>

                        <div className="grid gap-2">
                            <Label id="mode-label">{t('How it rewards')}</Label>
                            <ToggleGroup
                                type="single"
                                variant="segmented"
                                aria-labelledby="mode-label"
                                className="self-start justify-self-start"
                                disabled={held}
                                value={data.mode}
                                onValueChange={(mode) =>
                                    mode && changeMode(mode as CardMode)
                                }
                            >
                                <ToggleGroupItem value="cyclic">
                                    {t('Cyclic')}
                                </ToggleGroupItem>
                                <ToggleGroupItem value="progressive">
                                    {t('Progressive')}
                                </ToggleGroupItem>
                            </ToggleGroup>
                            <p className="text-sm text-muted-foreground">
                                {progressive
                                    ? t(
                                          'A reward at each tier; the card never starts again.',
                                      )
                                    : t(
                                          'A reward each time the card is full; it then starts again.',
                                      )}
                            </p>
                            {held ? (
                                <Alert>
                                    <Info />
                                    <AlertDescription>
                                        {t(
                                            'Customers hold this card: its mode and tiers are fixed, and its stamps can only go down.',
                                        )}
                                    </AlertDescription>
                                </Alert>
                            ) : null}
                            <InputError message={errors.mode} />
                        </div>

                        {progressive ? (
                            <div className="grid gap-2">
                                <Label>{t('Tiers')}</Label>
                                <TierEditor
                                    tiers={data.tiers}
                                    onChange={(changed) =>
                                        setData('tiers', changed)
                                    }
                                    disabled={held}
                                    errors={errors}
                                />
                            </div>
                        ) : (
                            <>
                                <div className="grid gap-2">
                                    <Label htmlFor="stamps_required">
                                        {t('Stamps to earn the reward')}
                                    </Label>
                                    <div className="flex items-center gap-2">
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="icon"
                                            aria-label={t('One stamp fewer')}
                                            disabled={stamps <= 5}
                                            onClick={() =>
                                                setData(
                                                    'stamps_required',
                                                    String(stamps - 1),
                                                )
                                            }
                                        >
                                            <Minus />
                                        </Button>
                                        <Input
                                            id="stamps_required"
                                            type="number"
                                            inputMode="numeric"
                                            required
                                            min={5}
                                            max={maxStamps}
                                            value={data.stamps_required}
                                            onChange={(event) =>
                                                setData(
                                                    'stamps_required',
                                                    event.target.value,
                                                )
                                            }
                                            className="w-20"
                                        />
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="icon"
                                            aria-label={t('One stamp more')}
                                            disabled={stamps >= maxStamps}
                                            onClick={() =>
                                                setData(
                                                    'stamps_required',
                                                    String(stamps + 1),
                                                )
                                            }
                                        >
                                            <Plus />
                                        </Button>
                                    </div>
                                    <InputError
                                        message={errors.stamps_required}
                                    />
                                </div>

                                <div className="grid gap-2">
                                    <Label id="reward-type-label">
                                        {t('Reward')}
                                    </Label>
                                    <ToggleGroup
                                        type="single"
                                        variant="segmented"
                                        aria-labelledby="reward-type-label"
                                        className="self-start justify-self-start"
                                        value={data.reward_type}
                                        onValueChange={(type) =>
                                            type &&
                                            setData(
                                                'reward_type',
                                                type as RewardType,
                                            )
                                        }
                                    >
                                        <ToggleGroupItem value="item">
                                            {t('Free item')}
                                        </ToggleGroupItem>
                                        <ToggleGroupItem value="percent">
                                            {t('% off')}
                                        </ToggleGroupItem>
                                        <ToggleGroupItem value="fixed">
                                            {t('Fixed amount')}
                                        </ToggleGroupItem>
                                    </ToggleGroup>
                                    <InputError message={errors.reward_type} />
                                </div>

                                {data.reward_type === 'item' ? null : (
                                    <div className="grid gap-2">
                                        <Label htmlFor="reward_value">
                                            {data.reward_type === 'percent'
                                                ? t('Percent off')
                                                : t('Amount off, in dirhams')}
                                        </Label>
                                        <Input
                                            id="reward_value"
                                            inputMode="decimal"
                                            required
                                            className="w-32"
                                            value={data.reward_value}
                                            onChange={(event) =>
                                                setData(
                                                    'reward_value',
                                                    event.target.value,
                                                )
                                            }
                                        />
                                        <InputError
                                            message={errors.reward_value}
                                        />
                                    </div>
                                )}

                                <div className="grid gap-2">
                                    <Label htmlFor="reward_text">
                                        {t('What customers read')}
                                    </Label>
                                    <Input
                                        id="reward_text"
                                        dir={typedDir(data.reward_text)}
                                        required
                                        maxLength={120}
                                        placeholder={t('A free coffee')}
                                        value={data.reward_text}
                                        onChange={(event) =>
                                            setData(
                                                'reward_text',
                                                event.target.value,
                                            )
                                        }
                                    />
                                    <InputError message={errors.reward_text} />
                                </div>

                                {rewardChanged ? (
                                    <Alert>
                                        <Info />
                                        <AlertDescription>
                                            {t(
                                                'Customers who hold this card get the new reward when they next fill it. Rewards already earned stay as they were.',
                                            )}
                                        </AlertDescription>
                                    </Alert>
                                ) : null}
                            </>
                        )}
                    </section>

                    <Separator />

                    <section className="flex flex-col gap-6">
                        <div className="grid gap-2">
                            <Label>{t('Colour')}</Label>
                            <BrandColorPicker
                                value={data.brand_color}
                                organizationColor={preview.organizationColor}
                                onChange={(color) =>
                                    setData('brand_color', color)
                                }
                            />
                            {isWeakBrand(brand) ? (
                                <Alert>
                                    <TriangleAlert />
                                    <AlertDescription>
                                        {t(
                                            'Only pure white or black text reads on this colour: a darker or lighter shade will look better.',
                                        )}
                                    </AlertDescription>
                                </Alert>
                            ) : null}
                            <InputError message={errors.brand_color} />
                        </div>

                        <div className="grid gap-2">
                            <Label id="stamp-style-label">{t('Stamp')}</Label>
                            <ToggleGroup
                                type="single"
                                variant="segmented"
                                aria-labelledby="stamp-style-label"
                                className="flex-wrap self-start justify-self-start"
                                value={data.stamp_style}
                                onValueChange={(style) =>
                                    style &&
                                    setData('stamp_style', style as StampStyle)
                                }
                            >
                                {STAMP_STYLE_OPTIONS.map((style) => (
                                    <ToggleGroupItem
                                        key={style.value}
                                        value={style.value}
                                        disabled={
                                            style.value === 'logo' &&
                                            !preview.logoUrl
                                        }
                                    >
                                        {t(style.label)}
                                    </ToggleGroupItem>
                                ))}
                            </ToggleGroup>
                            {preview.logoUrl ? null : (
                                <p className="text-sm text-muted-foreground">
                                    {t('Add your logo to stamp with it.')}
                                </p>
                            )}
                            <InputError message={errors.stamp_style} />
                        </div>

                        {card ? (
                            <BannerUpload cardId={card.id} />
                        ) : (
                            <p className="text-sm text-muted-foreground">
                                {t('Save the card to add a banner.')}
                            </p>
                        )}
                    </section>

                    <Separator />

                    <section className="flex flex-col gap-6">
                        <div className="grid gap-2">
                            <Label htmlFor="info_hours">{t('Hours')}</Label>
                            <Input
                                id="info_hours"
                                dir={typedDir(data.info.hours)}
                                maxLength={200}
                                placeholder={t('Every day, 8:00 to 20:00')}
                                value={data.info.hours}
                                onChange={(event) =>
                                    setInfo({ hours: event.target.value })
                                }
                            />
                            <InputError message={errors['info.hours']} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="info_phone">{t('Phone')}</Label>
                            <Input
                                id="info_phone"
                                type="tel"
                                dir="ltr"
                                maxLength={30}
                                value={data.info.phone}
                                onChange={(event) =>
                                    setInfo({ phone: event.target.value })
                                }
                            />
                            <InputError message={errors['info.phone']} />
                        </div>

                        <div className="grid gap-2">
                            <Label>{t('Links')}</Label>
                            <ul className="flex flex-col gap-3">
                                {data.info.links.map((link, at) => (
                                    <li
                                        key={at}
                                        className="flex flex-col gap-1"
                                    >
                                        <div className="flex items-center gap-2">
                                            <Input
                                                dir={typedDir(link.label)}
                                                maxLength={40}
                                                placeholder={t('Menu')}
                                                aria-label={t(
                                                    'Label of link :number',
                                                    { number: at + 1 },
                                                )}
                                                value={link.label}
                                                onChange={(event) =>
                                                    changeLink(at, {
                                                        label: event.target
                                                            .value,
                                                    })
                                                }
                                                className="w-32 shrink-0"
                                            />
                                            <Input
                                                type="url"
                                                dir="ltr"
                                                maxLength={255}
                                                placeholder="https://"
                                                aria-label={t(
                                                    'Address of link :number',
                                                    { number: at + 1 },
                                                )}
                                                value={link.url}
                                                onChange={(event) =>
                                                    changeLink(at, {
                                                        url: event.target.value,
                                                    })
                                                }
                                            />
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="icon"
                                                aria-label={t(
                                                    'Remove link :number',
                                                    { number: at + 1 },
                                                )}
                                                onClick={() =>
                                                    setInfo({
                                                        links: data.info.links.filter(
                                                            (_, i) => i !== at,
                                                        ),
                                                    })
                                                }
                                            >
                                                <Trash2 />
                                            </Button>
                                        </div>
                                        <InputError
                                            message={
                                                errors[
                                                    `info.links.${at}.label`
                                                ] ??
                                                errors[`info.links.${at}.url`]
                                            }
                                        />
                                    </li>
                                ))}
                            </ul>
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                className="justify-self-start"
                                disabled={data.info.links.length >= 5}
                                onClick={() =>
                                    setInfo({
                                        links: [
                                            ...data.info.links,
                                            { label: '', url: '' },
                                        ],
                                    })
                                }
                            >
                                <Plus />
                                {t('Add a link')}
                            </Button>
                            <InputError message={errors['info.links']} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="terms">{t('Terms')}</Label>
                            <Textarea
                                id="terms"
                                dir={typedDir(data.terms)}
                                maxLength={2000}
                                placeholder={t('One stamp per visit.')}
                                value={data.terms}
                                onChange={(event) =>
                                    setData('terms', event.target.value)
                                }
                            />
                            <InputError message={errors.terms} />
                        </div>
                    </section>

                    <Separator />

                    <section className="flex flex-col gap-6">
                        <div className="grid gap-2">
                            <Label htmlFor="cooldown_min">
                                {t('Minutes between two stamps')}
                            </Label>
                            <Input
                                id="cooldown_min"
                                type="number"
                                inputMode="numeric"
                                required
                                min={0}
                                max={1440}
                                className="w-32"
                                value={data.cooldown_min}
                                onChange={(event) =>
                                    setData('cooldown_min', event.target.value)
                                }
                            />
                            <p className="text-sm text-muted-foreground">
                                {t(
                                    'A customer waits this long before their next stamp on this card.',
                                )}
                            </p>
                            <InputError message={errors.cooldown_min} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="daily_cap">
                                {t('Stamps per day')}
                            </Label>
                            <Input
                                id="daily_cap"
                                type="number"
                                inputMode="numeric"
                                min={1}
                                max={100}
                                className="w-32"
                                value={data.daily_cap}
                                onChange={(event) =>
                                    setData('daily_cap', event.target.value)
                                }
                            />
                            <p className="text-sm text-muted-foreground">
                                {t(
                                    'The most one customer gets in a day at one business. Leave it empty for no limit.',
                                )}
                            </p>
                            <InputError message={errors.daily_cap} />
                        </div>
                    </section>

                    <div className="flex flex-wrap gap-3">
                        {card?.active ? (
                            <Button type="submit" disabled={processing}>
                                {processing && <Spinner />}
                                {t('Save')}
                            </Button>
                        ) : (
                            <>
                                <Button
                                    type="submit"
                                    variant="secondary"
                                    disabled={processing}
                                >
                                    {t('Save draft')}
                                </Button>
                                <Button
                                    type="submit"
                                    data-publish="true"
                                    disabled={processing}
                                >
                                    {processing && <Spinner />}
                                    {t('Publish')}
                                </Button>
                            </>
                        )}
                        <Button variant="ghost" asChild>
                            <Link href={index()}>{t('Cancel')}</Link>
                        </Button>
                    </div>
                </form>
            </div>
        </>
    );
}

CardsEdit.layout = {
    breadcrumbs: [
        {
            title: 'Cards',
            href: index(),
        },
    ],
};
