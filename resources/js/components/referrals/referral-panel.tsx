import { Copy, HeartHandshake, Share2 } from 'lucide-react';
import { useEffect, useState } from 'react';
import { Button } from '@/components/ui/button';
import { useClipboard } from '@/hooks/use-clipboard';
import { useTranslation } from '@/hooks/use-translation';
import { cn } from '@/lib/utils';

export type Referral = {
    /** Their join link, with their code. */
    url: string;
    /** The most stamps a month from friends (the card's setting). */
    cap: number;
    /** Stamps from friends this month. */
    thisMonth: number;
};

/**
 * "Invite a friend" on a customer's card (CHW-47, C6, design 20a): what
 * they get, Share (the phone's share sheet, when it has one) and Copy link,
 * and this month's stamps from friends against the limit. At the limit it
 * says more come next month: sharing still brings friends to the place.
 */
export function ReferralPanel({
    referral,
    place,
}: {
    referral: Referral;
    place: string;
}) {
    const { t } = useTranslation();
    const [copied, copy] = useClipboard();
    // Read once mounted: the server has no navigator.
    const [canShare, setCanShare] = useState(false);

    useEffect(() => {
        setCanShare(typeof navigator.share === 'function');
    }, []);

    // Copying can fail (no permission, an older browser): the link is then shown, to copy by hand.
    const [copyFailed, setCopyFailed] = useState(false);
    const copyLink = async () => setCopyFailed(!(await copy(referral.url)));

    const share = async () => {
        try {
            await navigator.share({
                title: place,
                text: t('Collect stamps at :place with me.', { place }),
                url: referral.url,
            });
        } catch (error) {
            // Closed without sharing: nothing to say. Anything else: copy the link instead.
            if (
                !(error instanceof DOMException && error.name === 'AbortError')
            ) {
                await copyLink();
            }
        }
    };
    const full = referral.thisMonth >= referral.cap;

    return (
        <section
            aria-labelledby="invite-a-friend"
            className="flex flex-col gap-3 rounded-card border border-stamp/40 bg-stamp/15 p-4"
            data-test="referral-panel"
        >
            <div className="flex items-start gap-3">
                <span className="flex size-10 shrink-0 items-center justify-center rounded-xl bg-card">
                    <HeartHandshake aria-hidden="true" className="size-4" />
                </span>
                <div className="flex min-w-0 flex-col gap-0.5">
                    <h2 id="invite-a-friend" className="font-semibold">
                        {t('Invite a friend')}
                    </h2>
                    <p className="text-sm text-pretty">
                        {t(
                            'When a friend you invite comes in for the first time, you get +1 stamp. Up to :count a month.',
                            { count: referral.cap },
                        )}
                    </p>
                </div>
            </div>
            <div className="flex gap-2">
                {canShare ? (
                    <Button
                        className="flex-1"
                        onClick={share}
                        data-test="share-referral"
                    >
                        <Share2 aria-hidden="true" />
                        {t('Share')}
                    </Button>
                ) : null}
                <Button
                    variant="secondary"
                    className="flex-1"
                    onClick={() => void copyLink()}
                    data-test="copy-referral"
                >
                    <Copy aria-hidden="true" />
                    {copied === referral.url
                        ? t('Link copied')
                        : t('Copy link')}
                </Button>
            </div>
            {copyFailed ? (
                <p className="flex flex-col gap-1 text-sm" role="status">
                    {t("Couldn't copy the link. Copy it here:")}
                    <span
                        className="rounded-md bg-card px-2 py-1 font-mono text-xs break-all select-all"
                        data-test="referral-url"
                    >
                        {referral.url}
                    </span>
                </p>
            ) : null}
            <div className="flex items-center gap-2 text-sm">
                <span aria-hidden="true" className="flex gap-1">
                    {Array.from({ length: referral.cap }, (_, i) => (
                        <span
                            key={i}
                            className={cn(
                                'h-1.5 w-4 rounded-full',
                                i < referral.thisMonth
                                    ? 'bg-primary'
                                    : 'bg-input',
                            )}
                        />
                    ))}
                </span>
                <span
                    className="text-muted-foreground"
                    data-test="referral-month"
                >
                    {full
                        ? t("You've had this month's :count: more next month", {
                              count: referral.cap,
                          })
                        : t(':count of :cap this month', {
                              count: referral.thisMonth,
                              cap: referral.cap,
                          })}
                </span>
            </div>
        </section>
    );
}
