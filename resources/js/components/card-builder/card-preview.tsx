import { Clock, Link2, Phone } from 'lucide-react';
import LoyaltyCard from '@/components/loyalty-card';
import type { StampStyle } from '@/components/loyalty-card';
import { useTranslation } from '@/hooks/use-translation';

export type CardInfo = {
    hours: string;
    phone: string;
    links: { label: string; url: string }[];
};

/**
 * The card builder's live preview (B6): a phone showing the banner, the real
 * LoyaltyCard and what customers read with it (hours, phone, links, terms).
 * Only http(s) links are links; anything else typed so far stays text.
 */
export default function CardPreview({
    businessName,
    cardName,
    logoUrl,
    bannerUrl,
    brandColor,
    stampsRequired,
    stampStyle,
    rewardText,
    info,
    terms,
}: {
    businessName: string;
    cardName: string;
    logoUrl: string | null;
    bannerUrl: string | null;
    brandColor: string | null;
    stampsRequired: number;
    stampStyle: StampStyle;
    rewardText: string;
    info: CardInfo;
    terms: string;
}) {
    const { t } = useTranslation();
    const links = info.links.filter(
        (link) => link.label.trim() !== '' && link.url.trim() !== '',
    );
    const hasDetails =
        info.hours.trim() !== '' ||
        info.phone.trim() !== '' ||
        links.length > 0;

    return (
        <figure className="mx-auto flex w-full max-w-sm flex-col gap-4 rounded-sheet border-8 bg-background p-3 shadow-sm">
            <figcaption className="sr-only">
                {t('Preview of the card')}
            </figcaption>
            {bannerUrl ? (
                <img
                    src={bannerUrl}
                    alt=""
                    className="h-24 w-full rounded-card object-cover"
                />
            ) : null}
            <LoyaltyCard
                businessName={businessName}
                cardName={cardName || null}
                logoUrl={logoUrl}
                brandColor={brandColor}
                stampsRequired={stampsRequired}
                stampsCollected={Math.min(3, stampsRequired)}
                stampStyle={stampStyle}
                rewardText={rewardText}
            />
            {hasDetails ? (
                <dl className="flex flex-col gap-2 px-2 text-sm">
                    {info.hours.trim() !== '' ? (
                        <div className="flex gap-2">
                            <dt>
                                <Clock aria-hidden="true" className="size-4" />
                                <span className="sr-only">{t('Hours')}</span>
                            </dt>
                            <dd dir="auto" className="whitespace-pre-line">
                                {info.hours}
                            </dd>
                        </div>
                    ) : null}
                    {info.phone.trim() !== '' ? (
                        <div className="flex gap-2">
                            <dt>
                                <Phone aria-hidden="true" className="size-4" />
                                <span className="sr-only">{t('Phone')}</span>
                            </dt>
                            <dd dir="ltr">{info.phone}</dd>
                        </div>
                    ) : null}
                    {links.length > 0 ? (
                        <div className="flex gap-2">
                            <dt>
                                <Link2 aria-hidden="true" className="size-4" />
                                <span className="sr-only">{t('Links')}</span>
                            </dt>
                            <dd>
                                <ul className="flex flex-wrap gap-x-3 gap-y-1">
                                    {links.map((link, index) => (
                                        <li key={index} dir="auto">
                                            {/^https?:\/\//i.test(link.url) ? (
                                                <a
                                                    href={link.url}
                                                    target="_blank"
                                                    rel="noopener noreferrer"
                                                    className="underline underline-offset-4"
                                                >
                                                    {link.label}
                                                </a>
                                            ) : (
                                                link.label
                                            )}
                                        </li>
                                    ))}
                                </ul>
                            </dd>
                        </div>
                    ) : null}
                </dl>
            ) : null}
            {terms.trim() !== '' ? (
                <p
                    dir="auto"
                    className="px-2 text-xs whitespace-pre-line text-muted-foreground"
                >
                    {terms}
                </p>
            ) : null}
        </figure>
    );
}
