import { Link } from '@inertiajs/react';
import { ScanLine } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/hooks/use-translation';
import { momentLabel } from '@/lib/local-moment';
import type { LocalMoment } from '@/lib/local-moment';
import { dismiss } from '@/routes/rewards/notice';

/** A reward staff redeemed with a scan of the customer's QR that nothing told them about yet (DescribeRedeemNotices). */
export type RedeemNoticeData = {
    id: number;
    reward: string;
    business: string;
    /** When, in the redeeming site's time. */
    at: LocalMoment;
};

/**
 * My cards' notice of a reward staff redeemed with a scan of the customer's
 * QR (CHW-228, design 14b), when no phone got the push: the reward, the
 * place, when and how, and what to do if it wasn't them. It stays until Got
 * it, on every phone they sign in on. The names are the business's own
 * words, in their own direction.
 */
export default function RedeemNotice({ notice }: { notice: RedeemNoticeData }) {
    const { t, tn, locale } = useTranslation();
    const when = momentLabel(
        locale,
        notice.at,
        {
            today: (time) => t('today at :time', { time }),
            other: (date, time) => t('on :date at :time', { date, time }),
        },
        { day: 'numeric', month: 'long' },
    );

    return (
        <section
            role="status"
            className="flex flex-col gap-2.5 rounded-card border bg-card px-4 py-3.5"
            data-test="redeem-notice"
        >
            <div className="flex items-start gap-3">
                <span className="flex size-9 shrink-0 items-center justify-center rounded-md bg-muted">
                    <ScanLine aria-hidden="true" className="size-4" />
                </span>
                <div className="grid gap-0.5">
                    <p className="text-md font-semibold">
                        {tn('Your :reward was redeemed', {
                            reward: <bdi>{notice.reward}</bdi>,
                        })}
                    </p>
                    <p className="text-sm text-muted-foreground">
                        {tn(
                            'At :business :when, by staff scanning your QR. Not you? Tell the staff.',
                            { business: <bdi>{notice.business}</bdi> },
                            { when },
                        )}
                    </p>
                </div>
            </div>
            <Button variant="outline" size="sm" className="self-start" asChild>
                <Link
                    href={dismiss(notice.id)}
                    as="button"
                    preserveScroll
                    data-test="redeem-notice-dismiss"
                >
                    {t('Got it')}
                </Link>
            </Button>
        </section>
    );
}
